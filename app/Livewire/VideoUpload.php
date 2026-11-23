<?php

namespace App\Livewire;

use App\Jobs\ProcessRailwayVideo;
use App\Models\ModelWeight;
use App\Models\ProcessingJob;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use RuntimeException;
use Throwable;

class VideoUpload extends Component
{
    use WithFileUploads;

    public $videoFile;

    public $modelWeightId;

    public $modelType = 'yolov8';

    public $confidence = 0.25;

    public $processingVideoId;

    public function mount(): void
    {
        $defaultModel = ModelWeight::query()
            ->where('model_type', 'yolov8')
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        $this->modelWeightId = $defaultModel?->id;
    }

    public function updatedModelType(): void
    {
        $this->resetErrorBag('modelWeightId');

        $this->modelWeightId = $this->modelType === 'ensemble'
            ? null
            : ModelWeight::query()
                ->where('model_type', $this->modelType)
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');
    }

    public function updatedVideoFile(): void
    {
        $this->resetErrorBag(['videoFile']);

        Log::channel('video_processing')->info('[UPLOAD] Video selected in Livewire temporary storage', [
            'original_name' => $this->videoFile?->getClientOriginalName(),
            'file_size' => $this->videoFile?->getSize(),
            'mime_type' => $this->videoFile?->getMimeType(),
        ]);
    }

    public function startAnalysis(): void
    {
        $logger = Log::channel('video_processing');
        $video = null;
        $inputPath = null;

        $logger->info('[UPLOAD] UPLOAD_METHOD_STARTED', [
            'video_file_exists' => $this->videoFile !== null,
            'model_weight_id' => $this->modelWeightId,
            'model_type' => $this->modelType,
            'confidence' => $this->confidence,
        ]);

        try {
            $logger->info('[UPLOAD] VALIDATION_STARTED');

            $modelWeightRules = $this->modelType === 'ensemble'
                ? ['nullable']
                : [
                    'required',
                    'integer',
                    Rule::exists('model_weights', 'id')->where(fn ($query) => $query
                        ->where('model_type', $this->modelType)
                        ->where('is_active', true)),
                ];

            $this->validate([
                'videoFile' => [
                    'required',
                    'file',
                    'mimes:mp4,avi,mov,mkv',
                    'max:512000',
                ],
                'modelWeightId' => [
                    ...$modelWeightRules,
                ],
                'modelType' => [
                    'required',
                    'in:yolov8,mobilenet,fasterrcnn,ensemble',
                ],
                'confidence' => [
                    'required',
                    'numeric',
                    'min:0.01',
                    'max:1',
                ],
            ]);

            $logger->info('[UPLOAD] VALIDATION_PASSED', [
                'video_name' => $this->videoFile?->getClientOriginalName(),
                'model_weight_id' => $this->modelWeightId,
                'model_type' => $this->modelType,
            ]);

            $modelWeight = $this->modelType === 'ensemble'
                ? null
                : ModelWeight::query()
                    ->whereKey($this->modelWeightId)
                    ->where('model_type', $this->modelType)
                    ->where('is_active', true)
                    ->firstOrFail();
            $modelPath = $modelWeight?->resolvedPath();
            $logger->info('[UPLOAD] MODEL_WEIGHT_FOUND', [
                'model_weight_id' => $modelWeight?->id,
                'model_name' => $modelWeight?->name ?? 'Ensemble (All Models)',
                'model_path' => $modelPath,
            ]);

            if ($this->modelType !== 'ensemble' && (! $modelPath || filesize($modelPath) <= 0)) {
                throw new RuntimeException('The configured model file is missing or empty. Expected it under python/models/.');
            }

            $storedName = uniqid('railway_', true).'.'.strtolower(
                $this->videoFile->getClientOriginalExtension() ?: 'mp4'
            );

            $logger->info('[UPLOAD] VIDEO_STORAGE_STARTED', [
                'stored_name' => $storedName,
            ]);

            $inputPath = $this->videoFile->storeAs('videos', $storedName, 'local');

            if (! $inputPath) {
                throw new RuntimeException('The uploaded video could not be stored permanently.');
            }

            $absoluteInputPath = Storage::disk('local')->path($inputPath);
            if (! is_file($absoluteInputPath)) {
                throw new RuntimeException('The stored video was not found on disk after upload: '.$absoluteInputPath);
            }

            $logger->info('[UPLOAD] VIDEO_STORAGE_COMPLETED', [
                'stored_name' => $storedName,
                'input_path' => $inputPath,
                'absolute_path' => $absoluteInputPath,
                'size_bytes' => $this->videoFile->getSize(),
            ]);

            $logger->info('[DATABASE] DATABASE_TRANSACTION_STARTED', [
                'video_name' => $this->videoFile->getClientOriginalName(),
            ]);

            $video = DB::transaction(function () use ($modelWeight, $storedName, $inputPath) {
                $video = Video::query()->create([
                    'original_name' => $this->videoFile->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'input_path' => $inputPath,
                    'status' => 'pending',
                    'stage' => 'queued',
                    'processed_frames' => 0,
                    'total_frames' => 0,
                    'model_weight_id' => $modelWeight?->id,
                    'model_type' => $this->modelType,
                    'model_name' => $modelWeight?->name ?? 'Ensemble (All Models)',
                    'file_size' => $this->videoFile->getSize(),
                    'confidence' => (float) $this->confidence,
                    'error_message' => null,
                ]);

                ProcessingJob::query()->create([
                    'video_id' => $video->id,
                    'status' => 'queued',
                    'error_message' => null,
                ]);

                $logger = Log::channel('video_processing');
                $logger->info('[DATABASE] VIDEO_RECORD_CREATED', [
                    'video_id' => $video->id,
                    'stored_name' => $storedName,
                    'input_path' => $inputPath,
                ]);

                $logger->info('[DATABASE] PROCESSING_JOB_CREATED', [
                    'video_id' => $video->id,
                    'processing_job_id' => $video->processingJob()->value('id'),
                ]);

                return $video;
            });

            $logger->info('[QUEUE] QUEUE_DISPATCH_STARTED', [
                'video_id' => $video->id,
            ]);

            ProcessRailwayVideo::dispatch($video->id)->afterCommit();

            $logger->info('[QUEUE] QUEUE_DISPATCH_COMPLETED', [
                'video_id' => $video->id,
                'queue' => config('queue.default'),
            ]);

            $this->processingVideoId = $video->id;
            $this->reset('videoFile');
            $this->dispatch('video-upload-complete');

            session()->flash('success', 'Video uploaded and queued successfully. '.($this->modelType === 'ensemble' ? 'Ensemble' : $modelWeight->name).' analysis has started.');
        } catch (Throwable $exception) {
            $logger->error('[ERROR] Upload failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($video && in_array($video->status, ['pending', 'processing'], true)) {
                try {
                    DB::transaction(function () use ($video, $exception): void {
                        $video->update([
                            'status' => 'failed',
                            'stage' => 'failed',
                            'error_message' => $exception->getMessage(),
                        ]);
                        $video->processingJob()->update([
                            'status' => 'failed',
                            'completed_at' => now(),
                            'error_message' => $exception->getMessage(),
                        ]);
                    });
                } catch (Throwable $stateException) {
                    $logger->error('[DATABASE] Failed to mark upload job as failed', [
                        'video_id' => $video->id,
                        'message' => $stateException->getMessage(),
                    ]);
                }
            } elseif (! $video && $inputPath) {
                Storage::disk('local')->delete($inputPath);
            }

            $this->addError('videoFile', 'Video upload failed: '.$exception->getMessage());
            session()->flash('error', 'Video upload failed: '.$exception->getMessage());
        }
    }

    public function render()
    {
        $processingVideo = $this->processingVideoId
            ? Video::query()->with('processingJob')->find($this->processingVideoId)
            : null;

        return view('livewire.video-upload', [
            'models' => ModelWeight::query()
                ->where('is_active', true)
                ->where('model_type', $this->modelType)
                ->orderBy('name')
                ->get(),
            'processingVideo' => $processingVideo,
        ])->layout('layouts.app');
    }
}
