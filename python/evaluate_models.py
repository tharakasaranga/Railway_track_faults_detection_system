import argparse
import json
import re
import sys
import time
from pathlib import Path
from typing import Any

import cv2
import torch
from torchmetrics.detection.mean_ap import MeanAveragePrecision

from model_runtime import MODEL_NAMES, load_model, predict_frame


MODEL_WEIGHTS = {
    "yolov8": Path(__file__).parent / "models" / "best" / "best.pt",
    "mobilenet": Path(__file__).parent / "models" / "mobileNet" / "best_mobilenet_ssd.pth",
    "fasterrcnn": Path(__file__).parent / "models" / "fasterRCnn" / "best_model.pth",
}


def normalize_class_name(name: str) -> str:
    return re.sub(r"[^a-z0-9]", "", name.lower())


def parse_arguments():
    parser = argparse.ArgumentParser(
        description="Evaluate the railway defect models on a COCO-format test dataset."
    )
    parser.add_argument("--images", type=Path, required=True, help="Directory containing test images")
    parser.add_argument("--annotations", type=Path, required=True, help="COCO instances JSON file")
    parser.add_argument("--yolo-weights", type=Path, default=MODEL_WEIGHTS["yolov8"])
    parser.add_argument("--mobilenet-weights", type=Path, default=MODEL_WEIGHTS["mobilenet"])
    parser.add_argument("--fasterrcnn-weights", type=Path, default=MODEL_WEIGHTS["fasterrcnn"])
    parser.add_argument("--confidence", type=float, default=0.001, help="Detection score threshold")
    parser.add_argument("--warmup", type=int, default=5, help="Number of initial test images excluded from FPS timing")
    parser.add_argument("--device", choices=["auto", "cpu", "cuda"], default="auto")
    return parser.parse_args()


def load_coco_samples(images_directory: Path, annotations_path: Path):
    with annotations_path.open("r", encoding="utf-8") as annotation_file:
        dataset = json.load(annotation_file)

    image_records = dataset.get("images")
    categories = dataset.get("categories")
    annotations = dataset.get("annotations")
    if not isinstance(image_records, list) or not image_records:
        raise ValueError("COCO annotations must contain a non-empty images list.")
    if not isinstance(categories, list) or not categories:
        raise ValueError("COCO annotations must contain a non-empty categories list.")
    if not isinstance(annotations, list):
        raise ValueError("COCO annotations must contain an annotations list.")

    category_ids = {}
    category_id_values = set()
    for category in categories:
        if not isinstance(category, dict) or "id" not in category or "name" not in category:
            raise ValueError("Each COCO category must define id and name.")
        name = normalize_class_name(str(category["name"]))
        category_id = int(category["id"])
        if not name or name in category_ids or category_id in category_id_values:
            raise ValueError(f"Duplicate or empty COCO category name: {category.get('name')!r}")
        category_ids[name] = category_id
        category_id_values.add(category_id)

    targets_by_image = {}
    image_paths = {}
    for image in image_records:
        image_id = int(image["id"])
        if image_id in image_paths:
            raise ValueError(f"Duplicate COCO image id {image_id}.")
        image_paths[image_id] = images_directory / image["file_name"]
        targets_by_image[image_id] = {"boxes": [], "labels": [], "iscrowd": []}

    for annotation in annotations:
        image_id = int(annotation["image_id"])
        if image_id not in targets_by_image:
            raise ValueError(f"Annotation references unknown image id {image_id}.")
        category_id = int(annotation["category_id"])
        if category_id not in category_id_values:
            raise ValueError(f"Annotation references unknown category id {category_id}.")
        x, y, width, height = (float(value) for value in annotation["bbox"])
        if width <= 0 or height <= 0:
            continue
        target = targets_by_image[image_id]
        target["boxes"].append([x, y, x + width, y + height])
        target["labels"].append(category_id)
        target["iscrowd"].append(int(annotation.get("iscrowd", 0)))

    samples = []
    for image_id, image_path in image_paths.items():
        target = targets_by_image[image_id]
        samples.append((
            image_id,
            image_path,
            {
                "boxes": torch.tensor(target["boxes"], dtype=torch.float32).reshape(-1, 4),
                "labels": torch.tensor(target["labels"], dtype=torch.int64),
                "iscrowd": torch.tensor(target["iscrowd"], dtype=torch.int64),
            },
        ))
    return samples, category_ids


def synchronize(device: torch.device) -> None:
    if device.type == "cuda":
        torch.cuda.synchronize(device)


def evaluate_model(
    model_type: str,
    weights_path: Path,
    samples: list[tuple[int, Path, dict[str, torch.Tensor]]],
    category_ids: dict[str, int],
    device: torch.device,
    confidence: float,
    warmup_images: int,
) -> dict[str, float]:
    model = load_model(model_type, weights_path, device)
    metric = MeanAveragePrecision(
        iou_type="bbox",
        box_format="xyxy",
        backend="faster_coco_eval",
    )
    measured_seconds = 0.0
    measured_images = 0

    for index, (_, image_path, target) in enumerate(samples):
        image = cv2.imread(str(image_path))
        if image is None:
            raise FileNotFoundError(f"Unable to read test image: {image_path}")

        synchronize(device)
        started = time.perf_counter()
        detections = predict_frame(model, image, confidence=confidence)
        synchronize(device)
        elapsed = time.perf_counter() - started
        if index >= warmup_images:
            measured_seconds += elapsed
            measured_images += 1

        boxes = []
        labels = []
        scores = []
        for detection in detections:
            category_key = normalize_class_name(detection["class_name"])
            if category_key not in category_ids:
                raise ValueError(
                    f"{model_type} predicts class {detection['class_name']!r}, "
                    "which is missing from the COCO categories."
                )
            boxes.append([detection[key] for key in ("x1", "y1", "x2", "y2")])
            labels.append(category_ids[category_key])
            scores.append(detection["confidence"])

        metric.update(
            [{
                "boxes": torch.tensor(boxes, dtype=torch.float32).reshape(-1, 4),
                "labels": torch.tensor(labels, dtype=torch.int64),
                "scores": torch.tensor(scores, dtype=torch.float32),
            }],
            [target],
        )

    metrics: dict[str, Any] = metric.compute()
    fps = measured_images / measured_seconds if measured_seconds > 0 else 0.0
    return {
        "map_50": float(metrics["map_50"].item()),
        "map_50_95": float(metrics["map"].item()),
        "fps": fps,
    }


def main():
    args = parse_arguments()
    if not args.images.is_dir():
        raise FileNotFoundError(f"Test image directory does not exist: {args.images}")
    if not args.annotations.is_file():
        raise FileNotFoundError(f"COCO annotations file does not exist: {args.annotations}")
    if not 0 <= args.confidence <= 1:
        raise ValueError("--confidence must be between 0 and 1.")
    if args.warmup < 0:
        raise ValueError("--warmup must be zero or greater.")

    samples, category_ids = load_coco_samples(args.images, args.annotations)
    warmup_images = min(args.warmup, len(samples) - 1)
    device = torch.device(
        "cuda" if args.device == "auto" and torch.cuda.is_available()
        else "cpu" if args.device == "auto"
        else args.device
    )
    if device.type == "cuda" and not torch.cuda.is_available():
        raise RuntimeError("CUDA was requested but is not available in this Python environment.")
    if device.type == "cpu":
        torch.set_num_threads(min(torch.get_num_threads(), 4))

    print(f"Test images: {len(samples)} | Device: {device}")
    model_weights = {
        "yolov8": args.yolo_weights,
        "mobilenet": args.mobilenet_weights,
        "fasterrcnn": args.fasterrcnn_weights,
    }
    results = {}
    for model_type, weights_path in model_weights.items():
        print(f"Evaluating {MODEL_NAMES[model_type]} ({weights_path})...")
        results[model_type] = evaluate_model(
            model_type=model_type,
            weights_path=weights_path,
            samples=samples,
            category_ids=category_ids,
            device=device,
            confidence=args.confidence,
            warmup_images=warmup_images,
        )

    print("\nModel comparison")
    print(f"{'Model':<16} {'mAP @ 0.50':>12} {'mAP @ 0.50:0.95':>18} {'FPS':>10}")
    for model_type, metrics in results.items():
        print(
            f"{MODEL_NAMES[model_type]:<16} "
            f"{metrics['map_50']:>12.4f} "
            f"{metrics['map_50_95']:>18.4f} "
            f"{metrics['fps']:>10.2f}"
        )


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"Evaluation failed: {error}", file=sys.stderr)
        sys.exit(1)
