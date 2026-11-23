import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from detection_deduplication import normalize_fastener_detections


class FastenerDetectionTest(unittest.TestCase):
    def test_legacy_fastener_labels_are_normalized_to_the_current_class(self):
        detections = normalize_fastener_detections([
            {
                "class_id": 6,
                "class_name": "fasteners",
                "confidence": 0.82,
                "x1": 10,
                "y1": 20,
                "x2": 30,
                "y2": 40,
            },
            {
                "class_id": 0,
                "class_name": "Cracks",
                "confidence": 0.9,
                "x1": 50,
                "y1": 60,
                "x2": 70,
                "y2": 80,
            },
        ])

        self.assertEqual(len(detections), 1)
        self.assertEqual(detections[0]["class_id"], 3)
        self.assertEqual(detections[0]["class_name"], "Fastener_Defect")
        self.assertEqual(detections[0]["confidence"], 0.82)


if __name__ == "__main__":
    unittest.main()
