<div class="container py-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark mb-1">Railway Fault Detection Dashboard</h2>
        <p class="text-muted mb-0">Real-time overview of AI track analyses and defect predictions.</p>
    </div>

    {{-- Metrics Grid --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <span class="text-muted small fw-semibold">Total Videos</span>
                    <h2 class="fw-bold text-dark mt-2 mb-0">{{ $totalVideos }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <span class="text-muted small fw-semibold">Completed</span>
                    <h2 class="fw-bold text-success mt-2 mb-0">{{ $completedVideos }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <span class="text-muted small fw-semibold">Processing</span>
                    <h2 class="fw-bold text-warning mt-2 mb-0">{{ $processingVideos }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <span class="text-muted small fw-semibold">Detected Defects</span>
                    <h2 class="fw-bold text-danger mt-2 mb-0">{{ $totalDetections }}</h2>
                </div>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Recent Track Analyses</h5>
                <a href="{{ route('videos.index') }}" class="btn btn-sm btn-outline-primary rounded-pill px-3">View All</a>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Video File</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentVideos as $video)
                            <tr>
                                <td class="fw-semibold text-dark">{{ $video->original_name }}</td>
                                <td>
                                    <span class="badge {{ $video->status === 'completed' ? 'bg-success' : ($video->status === 'processing' ? 'bg-warning text-dark' : 'bg-secondary') }} rounded-pill px-3">
                                        {{ ucfirst($video->status) }}
                                    </span>
                                </td>
                                <td>{{ $video->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No analysis tasks yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>