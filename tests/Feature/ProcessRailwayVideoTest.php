<?php

namespace Tests\Feature;

use App\Jobs\ProcessRailwayVideo;
use App\Models\ModelWeight;
use App\Models\ProcessingJob;
use App\Models\Video;
use App\Services\PythonInferenceService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessRailwayVideoTest extends TestCase
{
    public function test_it_does_not_start_a_cancelled_queued_video(): void
    {
        $video = Video::query()->create([
            'original_name' => 'cancelled.mp4',
            'stored_name' => 'cancelled.mp4',
            'input_path' => 'videos/cancelled.mp4',
            'status' => 'cancelled',
        ]);

        $python = Mockery::mock(PythonInferenceService::class);
        $python->shouldNotReceive('process');

        (new ProcessRailwayVideo($video->id))->handle($python);

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_it_persists_detection_rows_before_marking_the_video_complete(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('videos/input.mp4', 'video');
        Storage::disk('local')->put('outputs/input_annotated.mp4', 'annotated video');
        Storage::disk('local')->put(
            'outputs/input_annotated.mp4.detections/detection_00000001.jpg',
            'defect image'
        );
        $modelPath = tempnam(sys_get_temp_dir(), 'railway-model-');
        file_put_contents($modelPath, 'test weights');

        $modelWeight = ModelWeight::create([
            'name' => 'Test model',
            'filename' => 'best.pt',
            'path' => $modelPath,
            'is_active' => true,
            'is_default' => true,
        ]);

        $video = Video::create([
            'original_name' => 'input.mp4',
            'stored_name' => 'input.mp4',
            'input_path' => 'videos/input.mp4',
            'status' => 'pending',
            'model_weight_id' => $modelWeight->id,
            'model_name' => $modelWeight->name,
            'confidence' => 0.25,
        ]);

        $processingJob = ProcessingJob::create([
            'video_id' => $video->id,
            'status' => 'queued',
        ]);

        $python = Mockery::mock(PythonInferenceService::class);
        $python->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (...$arguments) {
                $onProgress = $arguments[4];
                $this->assertSame(
                    'outputs/input_annotated.mp4.detections',
                    $arguments[8]
                );
                $onProgress([
                    'event' => 'progress',
                    'stage' => 'processing',
                    'total_frames' => 10,
                    'processed_frames' => 5,
                    'progress' => 50,
                    'fps' => 2.5,
                    'elapsed_seconds' => 2,
                    'eta_seconds' => 2,
                ]);

                return [
                    'success' => true,
                    'total_frames' => 10,
                    'processed_frames' => 10,
                    'roi_review_frames' => 2,
                    'detections' => [[
                        'frame_number' => 3,
                        'class_name' => 'crack',
                        'class_id' => 0,
                        'confidence' => 0.91,
                        'x1' => 10,
                        'y1' => 20,
                        'x2' => 50,
                        'y2' => 60,
                        'risk_level' => 'low',
                        'image_path' => 'outputs/input_annotated.mp4.detections/detection_00000001.jpg',
                    ]],
                ];
            });

        try {
            (new ProcessRailwayVideo($video->id))->handle($python);
        } finally {
            @unlink($modelPath);
        }

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'status' => 'completed',
            'stage' => 'completed',
            'progress' => 100,
            'total_frames' => 10,
            'processed_frames' => 10,
            'fps' => 2.5,
            'eta_seconds' => 0,
            'roi_review_frames' => 2,
        ]);
        $this->assertDatabaseHas('processing_jobs', [
            'id' => $processingJob->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('detections', [
            'video_id' => $video->id,
            'frame_number' => 3,
            'class_name' => 'crack',
            'image_path' => 'outputs/input_annotated.mp4.detections/detection_00000001.jpg',
        ]);
    }

    public function test_it_passes_all_ensemble_weights_to_python_inference(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('videos/ensemble.mp4', 'video');
        Storage::disk('local')->put('outputs/ensemble_annotated.mp4', 'annotated video');

        $modelPaths = [];
        foreach ([
            'yolov8' => 'ensemble_yolo_checkpoint.pt',
            'mobilenet' => 'best_mobilenet_ssd.pth',
            'fasterrcnn' => 'best_model.pth',
        ] as $modelType => $filename) {
            $path = tempnam(sys_get_temp_dir(), 'railway-'.$modelType.'-');
            file_put_contents($path, 'test weights');
            $modelPaths[$modelType] = $path;

            ModelWeight::query()->create([
                'name' => $modelType,
                'filename' => $filename,
                'path' => $path,
                'model_type' => $modelType,
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        $video = Video::query()->create([
            'original_name' => 'ensemble.mp4',
            'stored_name' => 'ensemble.mp4',
            'input_path' => 'videos/ensemble.mp4',
            'status' => 'pending',
            'model_type' => 'ensemble',
            'model_name' => 'Ensemble (All Models)',
            'confidence' => 0.25,
        ]);
        $processingJob = ProcessingJob::query()->create([
            'video_id' => $video->id,
            'status' => 'queued',
        ]);

        $python = Mockery::mock(PythonInferenceService::class);
        $python->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($modelPaths) {
                $this->assertSame($modelPaths['yolov8'], $arguments[2]);
                $this->assertSame('ensemble', $arguments[6]);
                $this->assertSame([
                    'mobilenet' => $modelPaths['mobilenet'],
                    'fasterrcnn' => $modelPaths['fasterrcnn'],
                ], $arguments[7]);

                return [
                    'success' => true,
                    'total_frames' => 1,
                    'processed_frames' => 1,
                    'detection_count' => 0,
                ];
            });

        try {
            (new ProcessRailwayVideo($video->id))->handle($python);
        } finally {
            foreach ($modelPaths as $path) {
                @unlink($path);
            }
        }

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'model_type' => 'ensemble',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('processing_jobs', [
            'id' => $processingJob->id,
            'status' => 'completed',
        ]);
    }
}
