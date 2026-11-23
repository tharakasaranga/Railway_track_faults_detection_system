import sys
import unittest
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from railway_roi import RailwayTrackROI, bbox_roi_overlap_ratio, filter_detections_by_roi


class RailwayRoiTest(unittest.TestCase):
    def setUp(self):
        self.polygon = np.array(
            [[250, 200], [390, 200], [550, 470], [90, 470]],
            dtype=np.float32,
        )

    def test_overlap_uses_box_polygon_intersection(self):
        self.assertAlmostEqual(
            bbox_roi_overlap_ratio([300, 300, 340, 340], self.polygon),
            1.0,
        )
        self.assertEqual(
            bbox_roi_overlap_ratio([10, 300, 50, 340], self.polygon),
            0.0,
        )
        self.assertGreater(
            bbox_roi_overlap_ratio([225, 300, 265, 340], self.polygon),
            0.0,
        )

    def test_filter_applies_configurable_overlap_and_fails_closed(self):
        detections = [
            {"x1": 300, "y1": 300, "x2": 340, "y2": 340, "class_name": "Crack"},
            {"x1": 10, "y1": 300, "x2": 50, "y2": 340, "class_name": "Fastener_Defect"},
            {"x1": 140, "y1": 300, "x2": 180, "y2": 340, "class_name": "Rail_Surface_Defect"},
        ]

        self.assertEqual(
            [item["class_name"] for item in filter_detections_by_roi(detections, self.polygon, 0.35)],
            ["Crack"],
        )
        self.assertEqual(
            [item["class_name"] for item in filter_detections_by_roi(detections, self.polygon, 0.05)],
            ["Crack", "Rail_Surface_Defect"],
        )
        self.assertEqual(filter_detections_by_roi(detections, None, 0), [])

    def test_detects_perspective_rail_boundaries(self):
        frame = np.zeros((480, 640, 3), dtype=np.uint8)
        cv2.line(frame, (190, 479), (300, 210), (220, 220, 220), 12)
        cv2.line(frame, (450, 479), (340, 210), (220, 220, 220), 12)

        polygon = RailwayTrackROI().detect(frame)

        self.assertIsNotNone(polygon)
        self.assertEqual(polygon.shape, (4, 2))
        self.assertLess(polygon[0, 0], polygon[1, 0])
        self.assertLess(polygon[3, 0], polygon[2, 0])

    def test_returns_no_roi_for_blank_frame(self):
        polygon = RailwayTrackROI().detect(np.zeros((480, 640, 3), dtype=np.uint8))

        self.assertIsNone(polygon)


if __name__ == "__main__":
    unittest.main()
