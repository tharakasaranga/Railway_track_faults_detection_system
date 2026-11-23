<?php

namespace App\Jobs;

use App\Models\Detection;
use App\Models\ModelWeight;
use App\Models\Video;
use App\Services\PythonInferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessRailwayVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $videoId) {}

    public function handle(PythonInferenceService $python): void
    {
        $logger = Log::channel('video_processing');
        $logger->info('[QUEUE] QUEUE_JOB_STARTED', [
            'video_id' => $this->videoId,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
        ]);

        $video = null;
        $processingJob = null;
        $outputRelativePath = null;

        try {
            $video = DB::transaction(function (): ?Video {
                $video = Video::query()
                    ->with('modelWeight', 'processingJob')
                    ->lockForUpdate()
                    ->findOrFail($this->videoId);

                if ($video->status === 'cancelled') {
                    return null;
                }

                $video->update([
                    'status' => 'processing',
                    'stage' => 'loading_model',
                    'progress' => 0,
                    'error_message' => null,
                    'roi_review_frames' => 0,
                ]);
                $video->processingJob?->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'error_message' => null,
                ]);

                return $video;
            });

            if (! $video) {
                return;
            }

            $processingJob = $video->processingJob;

            $logger->info('[PYTHON] VIDEO_PROCESSING_INITIALIZING', [
                'video_id' => $video->id,
                'model_weight_id' => $video->model_weight_id,
                'model_type' => $video->model_type,
                'confidence' => $video->confidence,
            ]);

            $startTime = microtime(true);

            $inputPath = Storage::disk('local')->path($video->input_path);
            if (! is_file($inputPath)) {
                throw new \RuntimeException('Input video file was not found: '.$inputPath);
            }

            $outputRelativePath = 'outputs/'.pathinfo($video->stored_name, PATHINFO_FILENAME).'_annotated.mp4';
            $outputPath = Storage::disk('local')->path($outputRelativePath);
            $detectionImagesRelativePath = $outputRelativePath.'.detections';

            $modelType = $video->model_type ?: 'yolov8';
            $ensembleModelPaths = [];
            if ($modelType === 'ensemble') {
                foreach (['yolov8', 'mobilenet', 'fasterrcnn'] as $ensembleModelType) {
                    $weight = ModelWeight::query()
                        ->where('model_type', $ensembleModelType)
                        ->where('is_default', true)
                        ->where('is_active', true)
                        ->first();
                    $path = $weight?->resolvedPath();

                    if (! $path) {
                        throw new \RuntimeException('Active '.$ensembleModelType.' model weights were not found.');
                    }

                    $ensembleModelPaths[$ensembleModelType] = $path;
                }

                $modelPath = $ensembleModelPaths['yolov8'];
                unset($ensembleModelPaths['yolov8']);
            } else {
                $modelPath = $video->modelWeight?->resolvedPath();
            }

            if (! $modelPath) {
                throw new \RuntimeException('Selected model weights were not found.');
            }

            $pendingDetections = [];
            $detectionsCleared = false;

            $result = $python->process(
                inputPath: $inputPath,
                outputPath: $outputPath,
                modelPath: $modelPath,
                confidence: (float) ($video->confidence ?? 0.25),
                shouldCancel: fn (): bool => Video::query()->whereKey($video->id)->value('status') === 'cancelled',
                modelType: $modelType,
                ensembleModelPaths: $ensembleModelPaths,
                detectionImagesRelativePath: $detectionImagesRelativePath,
                onProgress: function (array $progress) use ($video, $logger, $startTime, &$pendingDetections, &$detectionsCleared): void {
                    if (($progress['event'] ?? null) === 'stage') {
                        $stage = (string) ($progress['stage'] ?? 'processing');
                        $video->update(['stage' => $stage]);
                        $logger->info('[PROGRESS] STAGE_CHANGED', [
                            'video_id' => $video->id,
                            'stage' => $stage,
                        ]);

                        return;
                    }

                    if (($progress['event'] ?? null) === 'detection') {
                        $pendingDetections[] = $progress['detection'] ?? [];
                        if (count($pendingDetections) >= 500) {
                            if (! $detectionsCleared) {
                                $video->detections()->delete();
                                $detectionsCleared = true;
                            }
                            $this->insertDetections($video, $pendingDetections);
                            $pendingDetections = [];
                        }

                        return;
                    }

                    if (($progress['event'] ?? null) !== 'progress') {
                        return;
                    }

                    $totalFrames = (int) ($progress['total_frames'] ?? 0);
                    $processedFrames = (int) ($progress['processed_frames'] ?? 0);
                    $percentage = $totalFrames > 0
                        ? min(100, round(($processedFrames / $totalFrames) * 100, 1))
                        : (float) ($progress['progress'] ?? 0);

                    $elapsedSeconds = (float) ($progress['elapsed_seconds'] ?? max(0, microtime(true) - $startTime));
                    $fps = (float) ($progress['fps'] ?? 0);
                    $etaSeconds = max(0, (float) ($progress['eta_seconds'] ?? 0));

                    $video->update([
                        'progress' => min(100, max(0, $percentage)),
                        'total_frames' => $totalFrames,
                        'processed_frames' => $processedFrames,
                        'stage' => $progress['stage'] ?? 'processing',
                        'fps' => $fps,
                        'eta_seconds' => (int) ceil($etaSeconds),
                    ]);

                    $logger->info('[PROGRESS] PROGRESS_UPDATE', [
                        'video_id' => $video->id,
                        'total_frames' => $totalFrames,
                        'processed_frames' => $processedFrames,
                        'progress_percent' => $percentage,
                        'fps' => round($fps, 2),
                        'elapsed_seconds' => round($elapsedSeconds, 1),
                        'eta_seconds' => round($etaSeconds, 1),
                        'stage' => $progress['stage'] ?? 'processing',
                    ]);
                }
            );

            if (! $detectionsCleared) {
                $video->detections()->delete();
            }
            if ($pendingDetections) {
                $this->insertDetections($video, $pendingDetections);
            }
            foreach (array_chunk($result['detections'] ?? [], 500) as $chunk) {
                $this->insertDetections($video, $chunk);
            }

            if (! is_file($outputPath) || filesize($outputPath) <= 0) {
                throw new \RuntimeException('Python inference completed without generating a playable output video.');
            }

            $processingTime = microtime(true) - $startTime;

            $completed = DB::transaction(function () use ($result, $video, $processingJob, $outputRelativePath, $processingTime): bool {
                $lockedVideo = Video::query()->lockForUpdate()->findOrFail($video->id);

                if ($lockedVideo->status === 'cancelled') {
                    return false;
                }

                $timestamp = now();
                $lockedVideo->update([
                    'status' => 'completed',
                    'stage' => 'completed',
                    'progress' => 100,
                    'output_path' => $outputRelativePath,
                    'duration' => $result['duration'] ?? null,
                    'total_frames' => (int) ($result['total_frames'] ?? $lockedVideo->total_frames ?? 0),
                    'processed_frames' => (int) ($result['processed_frames'] ?? $lockedVideo->processed_frames ?? 0),
                    'processing_time' => $processingTime,
                    'fps' => $result['fps'] ?? $lockedVideo->fps,
                    'eta_seconds' => 0,
                    'roi_review_frames' => (int) ($result['roi_review_frames'] ?? 0),
                    'error_message' => null,
                ]);

                $processingJob?->update([
                    'status' => 'completed',
                    'completed_at' => $timestamp,
                    'processing_time' => $processingTime,
                    'error_message' => null,
                ]);

                return true;
            });

            if (! $completed) {
                Storage::disk('local')->delete($outputRelativePath);
                Storage::disk('local')->deleteDirectory($detectionImagesRelativePath);
                $video->detections()->delete();

                return;
            }

            $logger->info('[PYTHON] VIDEO_ANALYSIS_COMPLETED', [
                'video_id' => $video->id,
                'detection_count' => $result['detection_count'] ?? count($result['detections'] ?? []),
                'processing_time_seconds' => round($processingTime, 2),
            ]);
        } catch (Throwable $exception) {
            if ($video && $video->fresh()?->status === 'cancelled') {
                if ($outputRelativePath) {
                    Storage::disk('local')->delete($outputRelativePath);
                    Storage::disk('local')->deleteDirectory($outputRelativePath.'.detections');
                }
                $video->detections()->delete();

                $logger->info('[QUEUE] VIDEO_ANALYSIS_CANCELLED', [
                    'video_id' => $video->id,
                ]);

                return;
            }

            $logger->error('[ERROR] Video analysis failed', [
                'video_id' => $video?->id ?? $this->videoId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($video) {
                if ($outputRelativePath) {
                    Storage::disk('local')->deleteDirectory($outputRelativePath.'.detections');
                }
                try {
                    DB::transaction(function () use ($video, $processingJob, $exception): void {
                        $video->update([
                            'status' => 'failed',
                            'stage' => 'failed',
                            'error_message' => $exception->getMessage(),
                        ]);
                        $processingJob?->update([
                            'status' => 'failed',
                            'completed_at' => now(),
                            'error_message' => $exception->getMessage(),
                        ]);
                    });
                    $video->detections()->delete();
                } catch (Throwable $stateException) {
                    $logger->error('[DATABASE] Failed to persist video failure state', [
                        'video_id' => $video->id,
                        'message' => $stateException->getMessage(),
                    ]);
                }
            }

            throw $exception;
        }
    }

    private function insertDetections(Video $video, array $detections): void
    {
        $timestamp = now();
        $rows = array_map(static function (array $detection) use ($video, $timestamp): array {
            return [
                'video_id' => $video->id,
                'frame_number' => $detection['frame_number'] ?? null,
                'class_name' => $detection['class_name'] ?? 'unknown',
                'class_id' => $detection['class_id'] ?? null,
                'confidence' => $detection['confidence'] ?? 0,
                'x1' => $detection['x1'] ?? 0,
                'y1' => $detection['y1'] ?? 0,
                'x2' => $detection['x2'] ?? 0,
                'y2' => $detection['y2'] ?? 0,
                'risk_level' => $detection['risk_level'] ?? null,
                'image_path' => $detection['image_path'] ?? null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }, $detections);

        if ($rows) {
            Detection::query()->insert($rows);
        }
    }
}
