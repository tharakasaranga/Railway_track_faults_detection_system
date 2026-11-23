<div class="container py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <div class="text-uppercase small fw-semibold text-primary mb-2">Detection system · model registry</div>
            <h1 class="h2 fw-bold text-dark mb-2">Models &amp; checkpoints</h1>
            <p class="text-secondary mb-0">Compare the three railway-defect detectors, their inference approach, and the checkpoint status configured in this installation.</p>
        </div>
        <a href="{{ route('videos.upload') }}" class="btn btn-primary rounded-pill px-4">Analyze a video <span aria-hidden="true">→</span></a>
    </div>

    <div class="alert alert-info border-0 rounded-4 mb-4" role="note">
        <strong>How to read checkpoint status:</strong> “Available” means the configured weight file is present and non-empty. It does not represent a validation score or guarantee detection accuracy.
    </div>

    <div class="row g-4 mb-4">
        @foreach ($models as $model)
            <div class="col-12 col-xl-4">
                <article class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="fs-3" aria-hidden="true">{{ $model['icon'] }}</span>
                                <div>
                                    <h2 class="h4 fw-bold mb-1">{{ $model['name'] }}</h2>
                                    <span class="badge rounded-pill bg-{{ $model['tone'] }}-subtle text-{{ $model['tone'] === 'warning' ? 'dark' : $model['tone'] }}">{{ $model['category'] }}</span>
                                </div>
                            </div>
                            <span class="badge rounded-pill bg-light text-dark border">{{ $model['video_count'] }} {{ \Illuminate\Support\Str::plural('analysis', $model['video_count']) }}</span>
                        </div>

                        <p class="text-secondary">{{ $model['summary'] }}</p>
                        <div class="rounded-3 bg-light p-3 mb-3">
                            <div class="small text-secondary mb-1">Architecture</div>
                            <div class="fw-semibold">{{ $model['architecture'] }}</div>
                            <div class="small text-secondary mt-2">{{ $model['input'] }}</div>
                        </div>

                        <h3 class="h6 fw-bold">Strengths</h3>
                        <ul class="small text-secondary ps-3 mb-3">
                            @foreach ($model['strengths'] as $strength)
                                <li class="mb-1">{{ $strength }}</li>
                            @endforeach
                        </ul>

                        <div class="small text-secondary border-start border-3 border-{{ $model['tone'] }} ps-3 mb-4">
                            <span class="fw-semibold text-dark">Consideration:</span> {{ $model['tradeoff'] }}
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h3 class="h6 fw-bold mb-0">Registered checkpoints</h3>
                            <span class="small text-secondary">{{ $model['weights']->count() }} configured</span>
                        </div>
                        @forelse ($model['weights'] as $weight)
                            <div class="border rounded-3 p-3 mb-2">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-break">{{ $weight->name }}</div>
                                        <div class="small text-secondary text-break">{{ $weight->filename }} · v{{ $weight->version ?: 'unspecified' }}</div>
                                    </div>
                                    <span class="badge rounded-pill {{ $weight->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $weight->is_active ? 'Available' : 'Unavailable' }}
                                    </span>
                                </div>
                                @if ($weight->is_default)
                                    <span class="badge text-bg-light border text-dark mt-2">Default selection</span>
                                @endif
                                @if ($weight->description)
                                    <p class="small text-secondary mb-0 mt-2">{{ $weight->description }}</p>
                                @endif
                            </div>
                        @empty
                            <div class="border rounded-3 p-3 small text-secondary">
                                No checkpoint is registered for this model yet. Run the model-weight seeder after placing the trained weights.
                            </div>
                        @endforelse
                    </div>
                </article>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1">Ensemble inference</h2>
                    <p class="text-secondary mb-0">Runs the configured YOLO, MobileNet, and Faster R-CNN checkpoints together.</p>
                </div>
                <span class="badge rounded-pill text-bg-dark align-self-start">{{ $ensembleCount }} ensemble {{ \Illuminate\Support\Str::plural('analysis', $ensembleCount) }}</span>
            </div>
        </div>
    </div>
</div>
