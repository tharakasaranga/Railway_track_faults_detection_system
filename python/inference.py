import argparse
import json
import os
import sys
import time
from pathlib import Path

import cv2
import imageio_ffmpeg
import numpy as np
import torch

from model_runtime import MODEL_NAMES, load_model, predict_frame
from detection_deduplication import TemporalDetectionDeduplicator, normalize_fastener_detections
from railway_roi import RailwayTrackROI, filter_detections_by_roi


MODEL_COLORS = {
    "yolov8": (255, 0, 0),
    "fastener": (0, 165, 255),
    "mobilenet": (0, 255, 0),
    "fasterrcnn": (255, 0, 255),
}
REVIEW_COLOR = (0, 165, 255)
FASTENER_CONFIDENCE = 0.15


def parse_roi_overlap(value):
    ratio = float(value)
    if not 0 <= ratio <= 1:
        raise argparse.ArgumentTypeError("ROI overlap must be between 0 and 1.")
    return ratio


def parse_roi_margin(value):
    ratio = float(value)
    if not 0 <= ratio <= 0.5:
        raise argparse.ArgumentTypeError("ROI margin must be between 0 and 0.5.")
    return ratio


def parse_arguments():
    parser = argparse.ArgumentParser(description="Railway track defect video inference")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", required=True, help="YOLO weights or selected single-model weights")
    parser.add_argument(
        "--model-type",
        choices=["yolov8", "mobilenet", "fasterrcnn", "ensemble"],
        default="yolov8",
    )
    parser.add_argument("--mobilenet-model", help="MobileNet-SSDLite weights for ensemble inference")
    parser.add_argument("--fasterrcnn-model", help="Faster R-CNN weights for ensemble inference")
    parser.add_argument("--fastener-model", help="Optional specialist checkpoint for rail fastener detection")
    parser.add_argument("--confidence", type=float, default=0.25)
    parser.add_argument(
        "--roi-min-overlap",
        type=parse_roi_overlap,
        default=os.getenv("RAILWAY_ROI_MIN_OVERLAP", "0.35"),
        help="Minimum fraction of a detection box that must overlap the track ROI.",
    )
    parser.add_argument(
        "--roi-margin-ratio",
        type=parse_roi_margin,
        default=os.getenv("RAILWAY_ROI_MARGIN_RATIO", "0.15"),
        help="Track-width fraction added outside both detected rails.",
    )
    parser.add_argument("--detections-output")
    parser.add_argument("--detection-images-dir")
    parser.add_argument("--cancel-file")
    return parser.parse_args()


def calculate_risk(class_name, x1, y1, x2, y2):
    width = max(0.0, x2 - x1)
    height = max(0.0, y2 - y1)
    area = width * height
    name = class_name.lower().strip()

    if name in {"crack", "broken_rail"} and area > 10000:
        return "high"
    if name in {"rail_surface_defect", "joint_fishplate_defect", "fastener_defect", "squat"} and area > 5000:
        return "medium"
    return "low"


def emit_stage(stage):
    print(json.dumps({"event": "stage", "stage": stage}), file=sys.stderr, flush=True)


def emit_progress(total_frames, processed_frames, fps, elapsed_seconds):
    percentage = min(100.0, (processed_frames / total_frames) * 100.0) if total_frames > 0 else 0
    remaining_frames = max(0, total_frames - processed_frames)
    eta_seconds = remaining_frames / fps if fps > 0 and remaining_frames > 0 else 0

    print(
        json.dumps({
            "event": "progress",
            "total_frames": total_frames,
            "processed_frames": processed_frames,
            "progress": round(percentage, 1),
            "fps": round(fps, 2),
            "elapsed_seconds": round(elapsed_seconds, 2),
            "eta_seconds": round(eta_seconds, 2),
        }),
        file=sys.stderr,
        flush=True,
    )


def main():
    args = parse_arguments()
    input_path = Path(args.input)
    output_path = Path(args.output)
    model_path = Path(args.model)
    detections_path = Path(args.detections_output) if args.detections_output else Path(str(output_path) + ".detections.jsonl")
    detection_images_dir = Path(args.detection_images_dir) if args.detection_images_dir else None
    cancel_path = Path(args.cancel_file) if args.cancel_file else None

    if not input_path.is_file():
        print(json.dumps({"success": False, "error": "Input video does not exist."}), flush=True)
        sys.exit(1)

    if args.model_type == "ensemble":
        if not args.mobilenet_model or not args.fasterrcnn_model:
            print(json.dumps({"success": False, "error": "Ensemble mode requires MobileNet and Faster R-CNN weights."}), flush=True)
            sys.exit(1)
        model_paths = {
            "yolov8": model_path,
            "mobilenet": Path(args.mobilenet_model),
            "fasterrcnn": Path(args.fasterrcnn_model),
        }
    else:
        model_paths = {args.model_type: model_path}
    if args.fastener_model:
        model_paths["fastener"] = Path(args.fastener_model)

    missing_models = [f"{model_type}: {path}" for model_type, path in model_paths.items() if not path.is_file()]
    if missing_models:
        print(json.dumps({"success": False, "error": "Model weights do not exist: " + ", ".join(missing_models)}), flush=True)
        sys.exit(1)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    detections_path.parent.mkdir(parents=True, exist_ok=True)
    if detection_images_dir:
        detection_images_dir.mkdir(parents=True, exist_ok=True)
    start_time = time.time()
    cap = None
    writer = None
    frame_number = 0
    detection_count = 0
    roi_review_frames = 0
    total_frames = 0
    duration = 0

    try:
        emit_stage("loading_model")
        device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        if device.type == "cpu":
            torch.set_num_threads(min(torch.get_num_threads(), 4))
        models = {
            model_type: load_model(model_type, path, device)
            for model_type, path in model_paths.items()
        }
        emit_stage("model_loaded")

        emit_stage("opening_video")
        cap = cv2.VideoCapture(str(input_path))
        if not cap.isOpened():
            raise RuntimeError("Unable to open input video.")
        emit_stage("video_opened")

        fps = cap.get(cv2.CAP_PROP_FPS)
        if fps <= 0:
            fps = 25.0

        width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        total_frames = max(0, int(cap.get(cv2.CAP_PROP_FRAME_COUNT)))
        duration = total_frames / fps if total_frames > 0 else 0
        if width <= 0 or height <= 0:
            raise RuntimeError("Input video has invalid frame dimensions.")
        roi_detector = RailwayTrackROI(margin_ratio=args.roi_margin_ratio)

        output_width = width + width % 2
        output_height = height + height % 2
        writer = imageio_ffmpeg.write_frames(
            str(output_path),
            size=(output_width, output_height),
            pix_fmt_in="rgb24",
            pix_fmt_out="yuv420p",
            fps=fps,
            quality=7,
            codec="libx264",
            macro_block_size=2,
            ffmpeg_log_level="error",
            output_params=["-movflags", "+faststart"],
        )
        writer.send(None)

        progress_step = max(1, total_frames // 100) if total_frames else 1
        last_progress_at = time.monotonic()
        emit_stage("processing")

        with detections_path.open("w", encoding="utf-8") as detections_file:
            deduplicator = TemporalDetectionDeduplicator()

            def save_unique_detections(detections):
                nonlocal detection_count
                for detection in detections:
                    detections_file.write(json.dumps(detection, separators=(",", ":")) + "\n")
                    detection_count += 1

            while True:
                if cancel_path and cancel_path.exists():
                    print(json.dumps({"success": False, "cancelled": True, "error": "Analysis was cancelled."}), flush=True)
                    return

                save_unique_detections(deduplicator.expire(frame_number))
                success, frame = cap.read()
                if not success:
                    break

                roi_polygon = roi_detector.detect(frame)
                frame_detections = []
                for model_type, loaded_model in models.items():
                    if model_type == "fastener":
                        model_detections = predict_frame(
                            loaded_model,
                            frame,
                            confidence=FASTENER_CONFIDENCE,
                        )
                        detections = normalize_fastener_detections(model_detections)
                    else:
                        model_detections = predict_frame(
                            loaded_model,
                            frame,
                            confidence=args.confidence,
                        )
                        if args.fastener_model:
                            model_detections = [
                                detection
                                for detection in model_detections
                                if "fastener" not in detection["class_name"].lower()
                            ]
                        detections = filter_detections_by_roi(
                            model_detections,
                            roi_polygon,
                            args.roi_min_overlap,
                        )

                    for detection in detections:
                        frame_detections.append((model_type, detection))

                source_frame = frame.copy() if detection_images_dir and frame_detections else None

                if roi_polygon is None:
                    roi_review_frames += 1
                    cv2.putText(
                        frame,
                        "TRACK ROI UNCERTAIN - REVIEW FRAME",
                        (20, 36),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.8,
                        REVIEW_COLOR,
                        2,
                        cv2.LINE_AA,
                    )
                for model_type, detection in frame_detections:
                    x1, y1, x2, y2 = (detection[key] for key in ("x1", "y1", "x2", "y2"))
                    class_name = detection["class_name"]
                    confidence = detection["confidence"]
                    risk_level = calculate_risk(class_name, x1, y1, x2, y2)
                    detection_record = {
                        "frame_number": frame_number,
                        "class_id": detection["class_id"],
                        "class_name": class_name,
                        "confidence": confidence,
                        "x1": x1,
                        "y1": y1,
                        "x2": x2,
                        "y2": y2,
                        "risk_level": risk_level,
                    }
                    track_id, is_representative = deduplicator.add(detection_record)
                    if detection_images_dir and source_frame is not None and is_representative:
                        image_height, image_width = source_frame.shape[:2]
                        box_left = max(0, min(image_width - 1, int(x1)))
                        box_top = max(0, min(image_height - 1, int(y1)))
                        box_right = max(box_left + 1, min(image_width, int(x2)))
                        box_bottom = max(box_top + 1, min(image_height, int(y2)))
                        box_width = box_right - box_left
                        box_height = box_bottom - box_top
                        padding_x = max(12, int(box_width * 0.15))
                        padding_y = max(12, int(box_height * 0.15))
                        left = max(0, box_left - padding_x)
                        top = max(0, box_top - padding_y)
                        right = min(image_width, box_right + padding_x)
                        bottom = min(image_height, box_bottom + padding_y)
                        crop = source_frame[top:bottom, left:right].copy()
                        if crop.size == 0:
                            raise RuntimeError("Unable to crop a detected defect from its source frame.")
                        cv2.rectangle(
                            crop,
                            (box_left - left, box_top - top),
                            (box_right - left - 1, box_bottom - top - 1),
                            MODEL_COLORS[model_type],
                            2,
                            cv2.LINE_AA,
                        )
                        image_filename = f"detection_{track_id:08d}.jpg"
                        image_path = detection_images_dir / image_filename
                        if not cv2.imwrite(str(image_path), crop, [cv2.IMWRITE_JPEG_QUALITY, 88]):
                            raise RuntimeError(f"Unable to save defect image: {image_path}")
                        detection_record["image_filename"] = image_filename

                    box_color = MODEL_COLORS[model_type]
                    cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), box_color, 2)
                    label = f"{MODEL_NAMES[model_type]}: {class_name} {confidence:.2f} [{risk_level}]"
                    cv2.putText(
                        frame,
                        label,
                        (int(x1), max(int(y1) - 10, 20)),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.9,
                        box_color,
                        2,
                    )

                if output_width != width or output_height != height:
                    frame = cv2.copyMakeBorder(
                        frame,
                        0,
                        output_height - height,
                        0,
                        output_width - width,
                        cv2.BORDER_REPLICATE,
                    )
                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                writer.send(rgb_frame.tobytes())
                frame_number += 1

                now = time.monotonic()
                elapsed_seconds = time.time() - start_time
                current_fps = frame_number / max(elapsed_seconds, 0.1)
                if frame_number % progress_step == 0 or frame_number == total_frames or now - last_progress_at >= 2:
                    emit_progress(total_frames, frame_number, current_fps, elapsed_seconds)
                    last_progress_at = now

            save_unique_detections(deduplicator.finish())

        if frame_number == 0:
            raise RuntimeError("Input video contains no readable frames.")
    finally:
        if cap is not None:
            cap.release()
        if writer is not None:
            writer.close()

    if not output_path.is_file() or output_path.stat().st_size == 0:
        raise RuntimeError("Annotated output video was not generated.")

    processing_time = time.time() - start_time
    emit_progress(total_frames, frame_number, frame_number / max(processing_time, 0.1), processing_time)

    print(
        json.dumps({
            "success": True,
            "total_frames": total_frames or frame_number,
            "processed_frames": frame_number,
            "duration": duration or frame_number / fps,
            "fps": frame_number / max(processing_time, 0.1),
            "processing_time": processing_time,
            "detection_count": detection_count,
            "roi_review_frames": roi_review_frames,
            "roi_min_overlap": args.roi_min_overlap,
        }),
        flush=True,
    )


if __name__ == "__main__":
    main()
