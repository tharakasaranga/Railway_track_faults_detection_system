from __future__ import annotations

from dataclasses import dataclass

import cv2
import numpy as np


@dataclass
class RailwayTrackROI:
    top_fraction: float = 0.42
    bottom_fraction: float = 0.98
    margin_ratio: float = 0.15
    smoothing: float = 0.35

    def __post_init__(self) -> None:
        self._previous_polygon: np.ndarray | None = None

    def detect(self, frame: np.ndarray) -> np.ndarray | None:
        height, width = frame.shape[:2]
        if width < 80 or height < 80:
            return None

        scale = min(1.0, 960 / max(width, height))
        if scale < 1:
            small = cv2.resize(
                frame,
                (max(1, round(width * scale)), max(1, round(height * scale))),
                interpolation=cv2.INTER_AREA,
            )
        else:
            small = frame

        small_height, small_width = small.shape[:2]
        gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
        gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
        gray = cv2.GaussianBlur(gray, (5, 5), 0)
        median = float(np.median(gray))
        edges = cv2.Canny(
            gray,
            max(20, int(0.66 * median)),
            min(255, int(1.33 * median)),
        )

        search_top = int(small_height * 0.32)
        search_mask = np.zeros_like(edges)
        cv2.rectangle(search_mask, (0, search_top), (small_width - 1, small_height - 1), 255, -1)
        edges = cv2.bitwise_and(edges, search_mask)

        min_line_length = max(30, int(small_height * 0.16))
        lines = cv2.HoughLinesP(
            edges,
            rho=1,
            theta=np.pi / 180,
            threshold=max(25, int(min_line_length * 0.45)),
            minLineLength=min_line_length,
            maxLineGap=max(12, int(small_height * 0.04)),
        )
        if lines is None:
            self._previous_polygon = None
            return None

        y_top = small_height * self.top_fraction
        y_bottom = small_height * self.bottom_fraction
        left_candidates: list[tuple[float, float, float]] = []
        right_candidates: list[tuple[float, float, float]] = []

        for line in lines.reshape(-1, 4):
            x1, y1, x2, y2 = (float(value) for value in line)
            delta_y = y2 - y1
            if abs(delta_y) < small_height * 0.14:
                continue
            slope = (x2 - x1) / delta_y
            if abs(slope) > 0.7:
                continue

            x_top = x1 + slope * (y_top - y1)
            x_bottom = x1 + slope * (y_bottom - y1)
            line_length = float(np.hypot(x2 - x1, y2 - y1))
            if not (
                -0.15 * small_width <= x_top <= 1.15 * small_width
                and -0.15 * small_width <= x_bottom <= 1.15 * small_width
            ):
                continue

            candidate = (x_top, x_bottom, line_length / small_height)
            if x_bottom < x_top:
                left_candidates.append(candidate)
            else:
                right_candidates.append(candidate)

        polygon = self._select_track_pair(
            left_candidates,
            right_candidates,
            small_width,
            small_height,
            y_top,
            y_bottom,
        )
        if polygon is None:
            self._previous_polygon = None
            return None

        if scale < 1:
            polygon /= scale

        if self._previous_polygon is not None:
            frame_diagonal = float(np.hypot(width, height))
            displacement = float(np.mean(np.linalg.norm(polygon - self._previous_polygon, axis=1)))
            if displacement <= frame_diagonal * 0.12:
                polygon = (
                    self.smoothing * polygon
                    + (1 - self.smoothing) * self._previous_polygon
                )

        self._previous_polygon = polygon.copy()
        return polygon

    def _select_track_pair(
        self,
        left_candidates: list[tuple[float, float, float]],
        right_candidates: list[tuple[float, float, float]],
        width: int,
        height: int,
        y_top: float,
        y_bottom: float,
    ) -> np.ndarray | None:
        best_score = 0.0
        best_pair: tuple[tuple[float, float, float], tuple[float, float, float]] | None = None

        for left in left_candidates:
            for right in right_candidates:
                top_gap = right[0] - left[0]
                bottom_gap = right[1] - left[1]
                top_center = (right[0] + left[0]) / 2
                bottom_center = (right[1] + left[1]) / 2

                if not (
                    width * 0.015 <= top_gap <= width * 0.45
                    and width * 0.12 <= bottom_gap <= width * 0.95
                    and bottom_gap >= top_gap * 1.2
                    and width * 0.25 <= top_center <= width * 0.75
                    and width * 0.08 <= bottom_center <= width * 0.92
                    and min(left[2], right[2]) >= 0.16
                ):
                    continue

                center_penalty = abs(top_center - width / 2) / width
                spread_penalty = abs(bottom_gap / width - 0.55)
                score = (
                    left[2]
                    + right[2]
                    - center_penalty
                    - spread_penalty * 0.25
                )
                if score > best_score:
                    best_score = score
                    best_pair = (left, right)

        if best_pair is None:
            return None

        left, right = best_pair
        top_margin = (right[0] - left[0]) * self.margin_ratio
        bottom_margin = (right[1] - left[1]) * self.margin_ratio
        return np.array(
            [
                [left[0] - top_margin, y_top],
                [right[0] + top_margin, y_top],
                [right[1] + bottom_margin, y_bottom],
                [left[1] - bottom_margin, y_bottom],
            ],
            dtype=np.float32,
        )


def bbox_roi_overlap_ratio(
    box: tuple[float, float, float, float] | list[float],
    polygon: np.ndarray,
) -> float:
    x1, y1, x2, y2 = (float(value) for value in box)
    box_area = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    if box_area == 0 or len(polygon) < 3:
        return 0.0

    rectangle = np.array(
        [[x1, y1], [x2, y1], [x2, y2], [x1, y2]],
        dtype=np.float32,
    )
    intersection_area, _ = cv2.intersectConvexConvex(
        np.asarray(polygon, dtype=np.float32).reshape(-1, 1, 2),
        rectangle.reshape(-1, 1, 2),
    )
    return min(1.0, max(0.0, float(intersection_area) / box_area))


def filter_detections_by_roi(
    detections: list[dict],
    polygon: np.ndarray | None,
    minimum_overlap: float,
) -> list[dict]:
    if polygon is None:
        return []

    return [
        detection
        for detection in detections
        if bbox_roi_overlap_ratio(
            [detection[key] for key in ("x1", "y1", "x2", "y2")],
            polygon,
        ) >= minimum_overlap
    ]
