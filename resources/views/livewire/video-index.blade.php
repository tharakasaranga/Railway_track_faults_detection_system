<div class="container py-4" wire:poll.5s>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">Analysis Results</h2>
            <p class="text-muted mb-0">All processed track videos and detection logs.</p>
        </div>
        <a href="{{ route('videos.upload') }}" class="btn btn-primary rounded-pill px-4">
            + Analyze New Video
        </a>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Video Name</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th>Uploaded Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($videos as $video)
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark">{{ $video->original_name }}</div>
                                    <small
                                        class="text-muted">{{ number_format(($video->file_size ?? 0) / 1024 / 1024, 2) }}
                                        MB</small>
                                </td>
                                <td>{{ $video->model_name ?? 'YOLOv8' }}</td>
                                <td>
                                    <span
                                        class="badge {{ $video->status === 'completed' ? 'bg-success' : ($video->status === 'failed' ? 'bg-danger' : ($video->status === 'processing' ? 'bg-warning text-dark' : 'bg-secondary')) }} rounded-pill px-3">
                                        {{ ucfirst($video->status) }}
                                    </span>
                                </td>
                                <td>{{ $video->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <a href="{{ route('videos.show', $video) }}"
                                        class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No video records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
