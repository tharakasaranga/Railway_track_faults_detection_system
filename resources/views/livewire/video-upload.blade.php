<div class="container py-4"
    x-on:video-upload-complete.window="$nextTick(() => document.getElementById('video-processing-progress')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">Analyze Railway Track Video</h2>
            <p class="text-muted mb-0">
                Upload a railway video for automated railway fault detection.
            </p>
        </div>

        <a href="{{ route('videos.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
            Back to List
        </a>
    </div>

    @if (session()->has('success'))
        <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill me-2"></i>
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4 p-md-5">

            <form wire:submit.prevent="startAnalysis" x-data="{
                isUploading: false,
                progress: 0,
                isSubmitting: false,
                fileSelected: false
            }"
                x-on:livewire-upload-start="isUploading = true; isSubmitting = true; progress = 0;"
                x-on:livewire-upload-finish="isUploading = false; isSubmitting = false; fileSelected = true;"
                x-on:livewire-upload-error="isUploading = false; fileSelected = false; isSubmitting = false;"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire:reset="fileSelected = false; isUploading = false; isSubmitting = false; progress = 0;">

                <div class="mb-4">
                    <label class="form-label fw-semibold">Railway Track Video</label>

                    <input type="file" class="form-control form-control-lg @error('videoFile') is-invalid @enderror"
                        wire:model="videoFile" accept=".mp4,.avi,.mov,.mkv"
                        @change="fileSelected = $event.target.files.length > 0">

                    @error('videoFile')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror

                    <div class="form-text">
                        MP4, AVI, MOV, MKV — maximum 500 MB.
                    </div>

                    <div x-show="isUploading" style="display:none;" class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold text-primary">
                                Uploading video...
                            </span>
                            <span class="small fw-bold text-primary" x-text="progress + '%'"></span>
                        </div>

                        <div class="progress" style="height:12px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                                :style="'width:' + progress + '%'"></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Model</label>

                        <select class="form-select form-select-lg @error('modelType') is-invalid @enderror"
                            wire:model.live="modelType">
                            <option value="yolov8">YOLOv8</option>
                            <option value="mobilenet">MobileNet-SSDLite</option>
                            <option value="fasterrcnn">Faster R-CNN</option>
                            <option value="ensemble">Ensemble (All Models)</option>
                        </select>

                        @error('modelType')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        @if ($modelType !== 'ensemble')
                            <label class="form-label fw-semibold">Model Weights</label>

                            <select class="form-select form-select-lg @error('modelWeightId') is-invalid @enderror"
                                wire:model="modelWeightId">
                                <option value="">-- Select Weights --</option>

                                @foreach ($models as $model)
                                    <option value="{{ $model->id }}">
                                        {{ $model->name }} ({{ $model->filename }})
                                    </option>
                                @endforeach
                            </select>

                            @error('modelWeightId')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        @else
                            <div class="form-text mt-2">
                                All three configured models will be applied to every frame.
                            </div>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Confidence Threshold
                        </label>

                        <input type="number"
                            class="form-control form-control-lg @error('confidence') is-invalid @enderror"
                            wire:model="confidence" min="0.01" max="1" step="0.01">

                        <div class="form-text">
                            Recommended: 0.25
                        </div>

                        @error('confidence')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill py-3 fw-bold"
                        :disabled="!fileSelected || isUploading || isSubmitting" wire:loading.attr="disabled"
                        wire:target="startAnalysis">

                        <span x-show="!isUploading && !isSubmitting" wire:loading.remove wire:target="startAnalysis">
                            <i class="bi bi-cpu me-2"></i>
                            Start Video Analysis
                        </span>

                        <span wire:loading wire:target="startAnalysis">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Starting Analysis...
                        </span>

                        <span x-show="isUploading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Uploading Video...
                        </span>

                        <span x-show="isSubmitting && !isUploading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Saving & Starting Analysis...
                        </span>

                    </button>
                </div>

            </form>
        </div>
    </div>

    {{-- Database-driven AI analysis progress --}}
    @if ($processingVideo)

        @php
            $percentage =
                $processingVideo->progress > 0 || $processingVideo->status === 'completed'
                    ? min(100, (float) $processingVideo->progress)
                    : ($processingVideo->total_frames > 0
                        ? min(
                            100,
                            round(($processingVideo->processed_frames / $processingVideo->total_frames) * 100, 1),
                        )
                        : 0);

            $isRunning =
                in_array($processingVideo->status, ['pending', 'processing']) ||
                in_array($processingVideo->processingJob?->status, ['queued', 'running']);

            $elapsedSeconds = max(
                0,
                (int) round(
                    $processingVideo->processing_time ??
                        ($processingVideo->processed_frames > 0
                            ? $processingVideo->processed_frames / max($processingVideo->fps ?? 1, 0.1)
                            : 0),
                ),
            );
            $etaSeconds = (int) ($processingVideo->eta_seconds ?? 0);
        @endphp

        <div id="video-processing-progress" class="card mt-4 border-0 shadow-sm rounded-4"
            @if ($isRunning) wire:poll.2s @endif>

            <div class="card-body p-4">

                @if ($processingVideo->status === 'failed' || $processingVideo->processingJob?->status === 'failed')

                    <div class="alert alert-danger mb-0">
                        <h5 class="alert-heading">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Video processing failed
                        </h5>

                        <p class="mb-0">
                            {{ $processingVideo->error_message ??
                                ($processingVideo->processingJob?->error_message ?? 'Unknown processing error.') }}
                        </p>
                    </div>
                @else
                    <h5 class="fw-bold mb-3">
                        @if ($processingVideo->status !== 'completed')
                            <span class="spinner-border spinner-border-sm text-primary me-2"></span>
                            Analyzing Railway Track Video...
                        @else
                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                            Video Analysis Completed
                        @endif
                    </h5>

                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-semibold">AI Analysis Progress</span>
                        <span class="fw-bold text-success">{{ number_format($percentage, 1) }}%</span>
                    </div>

                    <div class="progress" style="height:18px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                            role="progressbar" style="width: {{ $percentage }}%;"
                            aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-2 text-muted small">
                        <span>Status: <strong class="text-dark">{{ ucfirst($processingVideo->status) }}
                                ({{ str_replace('_', ' ', ucfirst($processingVideo->stage ?? 'queued')) }})</strong></span>
                        <span>{{ number_format($processingVideo->processed_frames) }} /
                            {{ number_format($processingVideo->total_frames) }} frames</span>
                    </div>

                    <div class="row g-2 mt-3 text-muted small">
                        <div class="col-md-3"><strong>FPS:</strong>
                            {{ $processingVideo->fps ? number_format($processingVideo->fps, 2) : '0.00' }}</div>
                        <div class="col-md-3"><strong>Elapsed:</strong> {{ gmdate('H:i:s', $elapsedSeconds) }}</div>
                        <div class="col-md-3"><strong>ETA:</strong>
                            {{ $etaSeconds > 0 ? gmdate('H:i:s', $etaSeconds) : '00:00:00' }}</div>
                        <div class="col-md-3"><strong>Detections:</strong>
                            {{ number_format($processingVideo->detections()->count()) }}</div>
                    </div>

                    @if ($processingVideo->status === 'completed')
                        <div class="alert alert-success mt-3 mb-0">
                            <i class="bi bi-check-circle me-2"></i>
                            The complete video has been analyzed and the results have been saved to the database.
                        </div>

                        <div class="mt-3 text-end">
                            <a href="{{ route('videos.show', $processingVideo->id) }}"
                                class="btn btn-success rounded-pill px-4">View Analysis Result</a>
                        </div>
                    @endif

                @endif
            </div>
        </div>
    @endif

</div>
