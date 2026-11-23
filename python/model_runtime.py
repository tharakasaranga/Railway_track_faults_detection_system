from dataclasses import dataclass
from functools import partial
from pathlib import Path
from typing import Any

import cv2
import torch
from torch import nn
from torchvision.models import mobilenet_v3_large
from torchvision.models.detection import (
    fasterrcnn_resnet50_fpn_v2,
)
from torchvision.models.detection import _utils as detection_utils
from torchvision.models.detection.anchor_utils import DefaultBoxGenerator
from torchvision.models.detection.ssdlite import SSDLiteHead, _mobilenet_extractor
from torchvision.models.detection.ssd import SSD
from ultralytics import YOLO


MODEL_NAMES = {
    "yolov8": "YOLO",
    "fastener": "Fastener specialist",
    "mobilenet": "MobileNet",
    "fasterrcnn": "Faster R-CNN",
}

FALLBACK_CLASS_NAMES = [
    "Crack",
    "Rail_Surface_Defect",
    "Joint_Fishplate_Defect",
    "Fastener_Defect",
]


@dataclass
class LoadedModel:
    model_type: str
    model: Any
    device: torch.device
    class_names: list[str]


def _names_from_checkpoint(checkpoint: dict[str, Any]) -> list[str]:
    names = checkpoint.get("class_names", FALLBACK_CLASS_NAMES)
    if isinstance(names, dict):
        names = [names[key] for key in sorted(names, key=lambda value: int(value))]
    if not isinstance(names, (list, tuple)) or not all(isinstance(name, str) for name in names):
        raise ValueError("Checkpoint class_names must be a list of class names.")
    return list(names)


def _build_mobilenet_ssdlite(num_classes: int):
    norm_layer = partial(nn.BatchNorm2d, eps=0.001, momentum=0.03)
    backbone = mobilenet_v3_large(
        weights=None,
        norm_layer=norm_layer,
        reduced_tail=False,
    )
    backbone = _mobilenet_extractor(backbone, trainable_layers=6, norm_layer=norm_layer)
    image_size = (320, 320)
    anchor_generator = DefaultBoxGenerator([[2, 3] for _ in range(6)], min_ratio=0.2, max_ratio=0.95)
    out_channels = detection_utils.retrieve_out_channels(backbone, image_size)
    num_anchors = anchor_generator.num_anchors_per_location()
    model = SSD(
        backbone,
        anchor_generator,
        image_size,
        num_classes,
        head=SSDLiteHead(out_channels, num_anchors, num_classes, norm_layer),
        score_thresh=0.001,
        nms_thresh=0.55,
        detections_per_img=300,
        topk_candidates=300,
        image_mean=[0.5, 0.5, 0.5],
        image_std=[0.5, 0.5, 0.5],
    )
    return model


def load_model(model_type: str, weights_path: str | Path, device: torch.device) -> LoadedModel:
    weights_path = Path(weights_path)
    if not weights_path.is_file():
        raise FileNotFoundError(f"{model_type} weights do not exist: {weights_path}")

    if model_type in {"yolov8", "fastener"}:
        model = YOLO(str(weights_path))
        names = model.names
        if isinstance(names, dict):
            class_names = [str(names.get(index, names.get(str(index), f"class_{index}"))) for index in range(len(names))]
        else:
            class_names = [str(name) for name in names]
        return LoadedModel(model_type, model, device, class_names)

    checkpoint = torch.load(weights_path, map_location="cpu", weights_only=True)
    if not isinstance(checkpoint, dict):
        raise ValueError(f"{model_type} checkpoint must contain a state dictionary.")
    state_dict = checkpoint.get("model_state_dict", checkpoint)
    if not isinstance(state_dict, dict):
        raise ValueError(f"{model_type} checkpoint has no model_state_dict.")

    class_names = _names_from_checkpoint(checkpoint)
    if model_type == "mobilenet":
        model = _build_mobilenet_ssdlite(num_classes=len(class_names) + 1)
    elif model_type == "fasterrcnn":
        model = fasterrcnn_resnet50_fpn_v2(
            weights=None,
            weights_backbone=None,
            num_classes=len(class_names) + 1,
        )
    else:
        raise ValueError(f"Unsupported model type: {model_type}")

    model.load_state_dict(state_dict)
    model.to(device)
    model.eval()
    return LoadedModel(model_type, model, device, class_names)


def predict_frame(
    loaded: LoadedModel,
    frame_bgr,
    confidence: float,
    iou_threshold: float = 0.7,
    max_detections: int = 300,
) -> list[dict[str, Any]]:
    if loaded.model_type in {"yolov8", "fastener"}:
        result = loaded.model.predict(
            source=frame_bgr,
            conf=confidence,
            imgsz=640,
            iou=iou_threshold,
            max_det=max_detections,
            device=str(loaded.device),
            verbose=False,
        )[0]
        detections = []
        if result.boxes is None:
            return detections

        for box in result.boxes:
            class_id = int(box.cls[0].item())
            x1, y1, x2, y2 = (float(value) for value in box.xyxy[0].tolist())
            detections.append({
                "class_id": class_id,
                "class_name": loaded.class_names[class_id],
                "confidence": float(box.conf[0].item()),
                "x1": x1,
                "y1": y1,
                "x2": x2,
                "y2": y2,
            })
        return detections

    frame_rgb = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB)
    image = torch.from_numpy(frame_rgb).permute(2, 0, 1).float().div(255).to(loaded.device)
    with torch.inference_mode():
        result = loaded.model([image])[0]

    detections = []
    for box, label, score in zip(result["boxes"], result["labels"], result["scores"]):
        confidence_value = float(score.item())
        class_index = int(label.item())
        if confidence_value < confidence or class_index < 1 or class_index > len(loaded.class_names):
            continue
        x1, y1, x2, y2 = (float(value) for value in box.tolist())
        detections.append({
            "class_id": class_index - 1,
            "class_name": loaded.class_names[class_index - 1],
            "confidence": confidence_value,
            "x1": x1,
            "y1": y1,
            "x2": x2,
            "y2": y2,
        })
        if len(detections) >= max_detections:
            break
    return detections

