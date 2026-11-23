from __future__ import annotations

from typing import Any


def normalize_fastener_detections(detections: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {
            **detection,
            "class_id": 3,
            "class_name": "Fastener_Defect",
        }
        for detection in detections
        if "fastener" in detection["class_name"].lower()
    ]


class TemporalDetectionDeduplicator:
    max_frame_gap = 30
    min_iou = 0.3
    same_frame_min_iou = 0.5

    def __init__(self) -> None:
        self._tracks: dict[int, dict[str, Any]] = {}
        self._next_track_id = 1

    @staticmethod
    def _class_key(class_name: str) -> str:
        return "".join(character.lower() for character in class_name if character.isalnum())

    @staticmethod
    def _iou(first: dict[str, Any], second: dict[str, Any]) -> float:
        left = max(float(first["x1"]), float(second["x1"]))
        top = max(float(first["y1"]), float(second["y1"]))
        right = min(float(first["x2"]), float(second["x2"]))
        bottom = min(float(first["y2"]), float(second["y2"]))
        intersection = max(0.0, right - left) * max(0.0, bottom - top)
        first_area = max(0.0, float(first["x2"]) - float(first["x1"])) * max(
            0.0, float(first["y2"]) - float(first["y1"])
        )
        second_area = max(0.0, float(second["x2"]) - float(second["x1"])) * max(
            0.0, float(second["y2"]) - float(second["y1"])
        )
        union = first_area + second_area - intersection

        return intersection / union if union > 0 else 0.0

    @staticmethod
    def _centers_close(first: dict[str, Any], second: dict[str, Any]) -> bool:
        first_center_x = (float(first["x1"]) + float(first["x2"])) / 2
        first_center_y = (float(first["y1"]) + float(first["y2"])) / 2
        second_center_x = (float(second["x1"]) + float(second["x2"])) / 2
        second_center_y = (float(second["y1"]) + float(second["y2"])) / 2
        width = max(
            float(first["x2"]) - float(first["x1"]),
            float(second["x2"]) - float(second["x1"]),
        )
        height = max(
            float(first["y2"]) - float(first["y1"]),
            float(second["y2"]) - float(second["y1"]),
        )

        return (
            abs(first_center_x - second_center_x) <= max(6.0, width * 0.5)
            and abs(first_center_y - second_center_y) <= max(6.0, height * 0.5)
        )

    def expire(self, frame_number: int) -> list[dict[str, Any]]:
        expired_ids = [
            track_id
            for track_id, track in self._tracks.items()
            if frame_number - track["last_frame"] > self.max_frame_gap
        ]
        expired = [self._tracks.pop(track_id)["representative"] for track_id in expired_ids]

        return expired

    def add(self, detection: dict[str, Any]) -> tuple[int, bool]:
        class_key = self._class_key(str(detection["class_name"]))
        matching_track_id = None
        matching_rank = (-1.0, float("-inf"))

        for track_id, track in self._tracks.items():
            if track["class_key"] != class_key:
                continue

            frame_gap = int(detection["frame_number"]) - track["last_frame"]
            if frame_gap < 0:
                continue

            last_detection = track["last_detection"]
            overlap = self._iou(last_detection, detection)
            minimum_iou = self.same_frame_min_iou if frame_gap == 0 else self.min_iou
            matches = overlap >= minimum_iou or (
                frame_gap > 0 and self._centers_close(last_detection, detection)
            )
            rank = (overlap, -frame_gap)
            if matches and rank > matching_rank:
                matching_track_id = track_id
                matching_rank = rank

        if matching_track_id is None:
            track_id = self._next_track_id
            self._next_track_id += 1
            self._tracks[track_id] = {
                "class_key": class_key,
                "last_frame": int(detection["frame_number"]),
                "last_detection": detection,
                "representative": detection,
            }

            return track_id, True

        track = self._tracks[matching_track_id]
        track["last_frame"] = int(detection["frame_number"])
        track["last_detection"] = detection
        is_representative = float(detection["confidence"]) > float(track["representative"]["confidence"])
        if is_representative:
            track["representative"] = detection

        return matching_track_id, is_representative

    def finish(self) -> list[dict[str, Any]]:
        representatives = [
            track["representative"]
            for _, track in sorted(self._tracks.items())
        ]
        self._tracks.clear()

        return representatives
