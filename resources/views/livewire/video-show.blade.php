<div class="container py-4" @if (in_array($video->status, ['pending', 'processing'])) wire:poll.2s @endif>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">Railway Track Analysis</h2>
            <p class="text-muted mb-0">{{ $video->original_name }}</p>
        </div>

        <div class="d-flex gap-2">
            @if (in_array($video->status, ['pending', 'processing']))
                <button type="button" class="btn btn-outline-danger rounded-pill px-4"
                    wire:click="stopAnalysis" wire:confirm="Stop this analysis?"
                    wire:loading.attr="disabled" wire:target="stopAnalysis">
                    <i class="bi bi-stop-circle me-1"></i>
                    <span wire:loading.remove wire:target="stopAnalysis">Stop Analysis</span>
                    <span wire:loading wire:target="stopAnalysis">Stopping...</span>
                </button>
            @endif

            <a href="{{ route('videos.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
                Back to List
            </a>
        </div>
    </div>

    @php
        $progress =
            $video->progress > 0 || $video->status === 'completed'
                ? min(100, (float) $video->progress)
                : ($video->total_frames > 0
                    ? min(100, round(($video->processed_frames / $video->total_frames) * 100, 1))
                    : 0);
    @endphp

    <div class="row g-4">

        <div class="col-lg-8">

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4">

                    <h5 class="fw-bold mb-3">
                        Annotated Output Video
                    </h5>

                    @if ($video->status === 'completed' && $video->output_path)
                        <video class="w-100 rounded-3 shadow-sm" controls preload="metadata">
                            <source src="{{ route('videos.output', $video) }}" type="video/mp4">
                            Your browser does not support video playback.
                        </video>
                        @if ($video->roi_review_frames > 0)
                            <div class="alert alert-warning mt-3 mb-0" role="alert">
                                <strong>Track boundary requires review.</strong>
                                The automatic track ROI could not be localized in
                                {{ number_format($video->roi_review_frames) }}
                                {{ \Illuminate\Support\Str::plural('frame', $video->roi_review_frames) }}.
                                Defect detections in those frames were withheld; see the annotated video for review markers.
                            </div>
                        @endif
                    @elseif (in_array($video->status, ['pending', 'processing']))
                        <div class="text-center py-5">

                            <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;">
                            </div>

                            <h5 class="fw-bold">
                                {{ $video->status === 'pending' ? 'Analysis queued' : 'YOLOv8 Analysis in Progress' }}
                            </h5>

                            <p class="text-muted">
                                @if ($video->status === 'pending' && $video->stage === 'queued')
                                    Waiting for a queue worker. Run <code>php artisan queue:work</code> to process queued videos.
                                @else
                                    Each video frame is being checked for railway track defects.
                                @endif
                            </p>

                            <div class="d-flex justify-content-between small fw-semibold mb-1">
                                <span>Analysis Progress</span>
                                <span>{{ number_format($progress, 1) }}%</span>
                            </div>

                            <div class="progress" style="height:14px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                    style="width:{{ $progress }}%">
                                </div>
                            </div>

                            <small class="text-muted d-block mt-2">
                                {{ number_format($video->processed_frames) }}
                                of
                                {{ $video->total_frames ? number_format($video->total_frames) : 'calculating' }}
                                frames analyzed.
                            </small>

                        </div>
                    @elseif ($video->status === 'failed')
                        <div class="alert alert-danger mb-0">
                            <strong>
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                Processing Failed
                            </strong>

                            <div class="mt-2">
                                {{ $video->error_message ?? 'Unknown error.' }}
                            </div>
                        </div>
                    @elseif ($video->status === 'cancelled')
                        <div class="alert alert-secondary mb-0">
                            <strong>
                                <i class="bi bi-stop-circle me-2"></i>
                                Analysis stopped
                            </strong>
                            <div class="mt-2">{{ $video->error_message ?? 'The analysis was cancelled.' }}</div>
                        </div>
                    @endif

                </div>
            </div>

            @if ($video->status === 'completed')
                <section class="card border-0 shadow-sm rounded-4 mt-4" aria-labelledby="defect-gallery-title">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                            <div>
                                <h5 id="defect-gallery-title" class="fw-bold mb-1">Defect Image Gallery</h5>
                                <p class="text-muted small mb-0">One cropped image for each detected defect, ordered by video frame.</p>
                            </div>
                            <span class="badge rounded-pill bg-primary-subtle text-primary">
                                {{ number_format($detectionImages->total()) }} {{ \Illuminate\Support\Str::plural('image', $detectionImages->total()) }}
                            </span>
                        </div>

                        @if ($detectionImages->count() > 0)
                            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3">
                                @foreach ($detectionImages as $detection)
                                    <div class="col" wire:key="detection-image-{{ $detection->id }}">
                                        <article class="card h-100 border rounded-3 overflow-hidden">
                                            <a
                                                href="{{ route('videos.detections.image', [$video, $detection]) }}"
                                                target="_blank"
                                                rel="noopener"
                                                aria-label="Open {{ $detection->class_name }} image from frame {{ ($detection->frame_number ?? 0) + 1 }}"
                                            >
                                                <img
                                                    src="{{ route('videos.detections.image', [$video, $detection]) }}"
                                                    class="card-img-top bg-dark"
                                                    alt="{{ str_replace('_', ' ', $detection->class_name) }} detected in frame {{ ($detection->frame_number ?? 0) + 1 }}"
                                                    loading="lazy"
                                                    style="height: 190px; object-fit: contain;"
                                                >
                                            </a>
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <h6 class="fw-bold text-capitalize mb-1">{{ str_replace('_', ' ', $detection->class_name) }}</h6>
                                                    @if ($detection->risk_level)
                                                        <span class="badge rounded-pill {{ $detection->risk_level === 'high' ? 'text-bg-danger' : ($detection->risk_level === 'medium' ? 'text-bg-warning' : 'text-bg-success') }}">
                                                            {{ ucfirst($detection->risk_level) }} risk
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="small text-muted">
                                                    Frame {{ number_format(($detection->frame_number ?? 0) + 1) }}
                                                    <span class="mx-1">·</span>
                                                    {{ number_format($detection->confidence * 100, 1) }}% confidence
                                                </div>
                                            </div>
                                        </article>
                                    </div>
                                @endforeach
                            </div>

                            @if ($detectionImages->hasPages())
                                <div class="d-flex justify-content-between align-items-center mt-4">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                                        wire:click="previousPage"
                                        @disabled($detectionImages->onFirstPage())
                                    >
                                        Previous
                                    </button>
                                    <span class="small text-muted">
                                        Page {{ $detectionImages->currentPage() }} of {{ $detectionImages->lastPage() }}
                                    </span>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                                        wire:click="nextPage"
                                        @disabled(! $detectionImages->hasMorePages())
                                    >
                                        Next
                                    </button>
                                </div>
                            @endif
                        @elseif ($detectionCount === 0)
                            <div class="text-center text-muted py-4">
                                No defects were detected, so there are no defect images to display.
                            </div>
                        @else
                            <div class="alert alert-info mb-0" role="status">
                                This analysis has detections but no saved defect crops. Re-run the analysis to generate its image gallery.
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            @if ($video->status === 'completed')
                <div class="card border-0 shadow-sm rounded-4 mt-4">
                    <div class="card-body p-4">

                        <h5 class="fw-bold mb-3">
                            Detection Confidence
                        </h5>

                        <div class="d-flex justify-content-between mb-1">
                            <span>Average model confidence</span>
                            <strong>{{ number_format($averageConfidence, 1) }}%</strong>
                        </div>

                        <div class="progress" style="height:14px;">
                            <div class="progress-bar" style="width:{{ $averageConfidence }}%">
                            </div>
                        </div>

                        <small class="text-muted d-block mt-2">
                            This is the average confidence of detected objects.
                            It is different from the video-processing progress percentage.
                        </small>

                    </div>
                </div>
            @endif

        </div>

        <div class="col-lg-4">

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">

                    <h5 class="fw-bold mb-3">Analysis Summary</h5>

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Status</span>
                        <span
                            class="badge {{ $video->status === 'completed' ? 'bg-success' : ($video->status === 'failed' ? 'bg-danger' : ($video->status === 'cancelled' ? 'bg-secondary' : 'bg-primary')) }} rounded-pill">
                            {{ ucfirst($video->status) }}
                        </span>
                    </div>

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Model</span>
                        <span class="fw-semibold">{{ $video->model_name }}</span>
                    </div>

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Processing Progress</span>
                        <span class="fw-semibold">{{ number_format($progress, 1) }}%</span>
                    </div>

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Total Frames</span>
                        <span class="fw-semibold">
                            {{ number_format($video->total_frames ?? 0) }}
                        </span>
                    </div>

                    @if ($video->status === 'completed')
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">ROI Review Frames</span>
                            <span class="fw-semibold">{{ number_format($video->roi_review_frames) }}</span>
                        </div>
                    @endif

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Duration</span>
                        <span class="fw-semibold">
                            {{ $video->duration !== null ? number_format($video->duration, 2) . ' sec' : 'N/A' }}
                        </span>
                    </div>

                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Processing Time</span>
                        <span class="fw-semibold">
                            {{ $video->processing_time !== null ? number_format($video->processing_time, 2) . ' sec' : 'N/A' }}
                        </span>
                    </div>

                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">

                    <h5 class="fw-bold mb-1">Detected Railway Defects</h5>
                    <p class="small text-muted mb-3">Repeated detections of the same class and overlapping location across nearby frames are counted once. The annotated video still shows boxes on every frame. Re-run older analyses to regenerate their gallery and deduplicated counts.</p>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="bg-light rounded-3 p-3 text-center">
                                <div class="fs-4 fw-bold">
                                    {{ $detectionCount }}
                                </div>
                                <small class="text-muted">
                                    Unique Defects
                                </small>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="bg-light rounded-3 p-3 text-center">
                                <div class="fs-4 fw-bold">
                                    {{ $uniqueClasses }}
                                </div>
                                <small class="text-muted">
                                    Fault Types
                                </small>
                            </div>
                        </div>
                    </div>

                    @forelse ($detectionGroups as $class => $count)
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded-3">
                            <span class="fw-semibold text-capitalize">
                                {{ $class }}
                            </span>

                            <span class="badge bg-primary rounded-pill px-3">
                                {{ $count }}
                            </span>
                        </div>

                    @empty

                        <div class="text-center text-muted py-3">
                            <i class="bi bi-shield-check text-success fs-3 d-block mb-1"></i>
                            No railway defects detected.
                        </div>
                    @endforelse

                </div>
            </div>

        </div>
    </div>
</div>
