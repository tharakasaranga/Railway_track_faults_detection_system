import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from detection_deduplication import TemporalDetectionDeduplicator


def detection(frame_number, class_name="Crack", x1=10, y1=10, x2=30, y2=30, confidence=0.8):
    return {
        "frame_number": frame_number,
        "class_name": class_name,
        "x1": x1,
        "y1": y1,
        "x2": x2,
        "y2": y2,
        "confidence": confidence,
    }


class TemporalDetectionDeduplicatorTest(unittest.TestCase):
    def test_merges_overlapping_same_class_detections_and_keeps_best_confidence(self):
        deduplicator = TemporalDetectionDeduplicator()
        track_id, is_representative = deduplicator.add(detection(0, confidence=0.8))
        self.assertTrue(is_representative)

        duplicate_track_id, is_representative = deduplicator.add(
            detection(1, class_name="crack", x1=11, y1=10, x2=31, y2=30, confidence=0.95)
        )

        self.assertEqual(track_id, duplicate_track_id)
        self.assertTrue(is_representative)
        self.assertEqual([1], [item["frame_number"] for item in deduplicator.finish()])

    def test_keeps_different_classes_and_separate_boxes_as_distinct_defects(self):
        deduplicator = TemporalDetectionDeduplicator()
        deduplicator.add(detection(0))
        deduplicator.add(detection(0, class_name="Fastener_Defect"))
        deduplicator.add(detection(1, x1=60, x2=80))

        self.assertEqual(3, len(deduplicator.finish()))

    def test_same_frame_overlapping_ensemble_detections_are_merged(self):
        deduplicator = TemporalDetectionDeduplicator()
        first_id, _ = deduplicator.add(detection(5, confidence=0.82))
        second_id, _ = deduplicator.add(detection(5, x1=11, x2=31, confidence=0.9))

        self.assertEqual(first_id, second_id)
        self.assertEqual(1, len(deduplicator.finish()))

    def test_detections_exactly_ten_frames_apart_keep_the_highest_confidence(self):
        deduplicator = TemporalDetectionDeduplicator()
        track_id, _ = deduplicator.add(detection(0, confidence=0.75))
        next_track_id, is_representative = deduplicator.add(
            detection(10, x1=11, x2=31, confidence=0.96)
        )

        self.assertEqual(track_id, next_track_id)
        self.assertTrue(is_representative)
        self.assertEqual([10], [item["frame_number"] for item in deduplicator.finish()])

    def test_detections_after_a_long_gap_start_a_new_defect_event(self):
        deduplicator = TemporalDetectionDeduplicator()
        first_id, _ = deduplicator.add(detection(0))

        expired = deduplicator.expire(31)
        self.assertEqual(1, len(expired))
        second_id, _ = deduplicator.add(detection(31))

        self.assertNotEqual(first_id, second_id)
        self.assertEqual(2, len(expired + deduplicator.finish()))

    def test_merges_same_defect_when_box_moves_between_frames(self):
        deduplicator = TemporalDetectionDeduplicator()
        track_id, _ = deduplicator.add(detection(0, confidence=0.8))

        moved_track_id, _ = deduplicator.add(
            detection(1, x1=18, x2=38, confidence=0.9)
        )

        self.assertEqual(track_id, moved_track_id)
        self.assertEqual(1, len(deduplicator.finish()))

    def test_keeps_track_across_a_brief_detection_gap(self):
        deduplicator = TemporalDetectionDeduplicator()
        track_id, _ = deduplicator.add(detection(0))

        self.assertEqual([], deduplicator.expire(20))
        resumed_track_id, _ = deduplicator.add(detection(20))

        self.assertEqual(track_id, resumed_track_id)
        self.assertEqual(1, len(deduplicator.finish()))

    def test_does_not_merge_distinct_nearby_same_class_defects(self):
        deduplicator = TemporalDetectionDeduplicator()
        first_id, _ = deduplicator.add(detection(0))
        second_id, _ = deduplicator.add(detection(0, x1=35, x2=55))

        self.assertNotEqual(first_id, second_id)
        self.assertEqual(2, len(deduplicator.finish()))


if __name__ == "__main__":
    unittest.main()
