<div class="container py-4">
    <div class="mb-4">
        <div class="text-uppercase small fw-semibold text-primary mb-2">Research workspace · operational evidence</div>
        <h1 class="h2 fw-bold text-dark mb-2">Research analytics</h1>
        <p class="text-secondary mb-0">Explore recorded video analyses and defect detections. These operational summaries are not a substitute for a labeled test-set benchmark.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <div class="small text-secondary fw-semibold">Videos analyzed</div>
                <div class="display-6 fw-bold mt-2">{{ number_format($totalVideos) }}</div>
                <div class="small text-secondary">All recorded submissions</div>
            </div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <div class="small text-secondary fw-semibold">Completed analyses</div>
                <div class="display-6 fw-bold text-success mt-2">{{ number_format($completedVideos) }}</div>
                <div class="small text-secondary">{{ number_format($completionRate, 1) }}% of submissions</div>
            </div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <div class="small text-secondary fw-semibold">Recorded detections</div>
                <div class="display-6 fw-bold text-primary mt-2">{{ number_format($detectionCount) }}</div>
                <div class="small text-secondary">Individual detected objects</div>
            </div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100 border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <div class="small text-secondary fw-semibold">Mean detection confidence</div>
                @if ($averageConfidence !== null)
                    <div class="display-6 fw-bold text-dark mt-2">{{ number_format($averageConfidence, 1) }}%</div>
                    <div class="small text-secondary">Across recorded detections</div>
                @else
                    <div class="h3 fw-bold text-secondary mt-3">No data</div>
                    <div class="small text-secondary">Recorded after an analysis completes</div>
                @endif
            </div></div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-7">
            <section class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                        <div><h2 class="h5 fw-bold mb-1">Analysis activity</h2><p class="small text-secondary mb-0">Video submissions per day · {{ $periodStart }} – {{ $periodEnd }}</p></div>
                        <span class="badge text-bg-light border text-dark">Last 7 days</span>
                    </div>
                    <div class="d-flex align-items-end gap-2 gap-md-3" style="height: 210px;" role="img" aria-label="Daily video submissions for the last seven days">
                        @foreach ($dailyTrend as $day)
                            <div class="d-flex flex-column align-items-center justify-content-end h-100 flex-fill">
                                <span class="small fw-semibold text-secondary mb-2">{{ $day['count'] }}</span>
                                <div class="w-100 rounded-top bg-primary" title="{{ $day['date'] }}: {{ $day['count'] }} analyses" style="height: {{ $day['count'] ? max(8, $day['count'] / $maxDailyCount * 145) : 3 }}px; opacity: {{ $day['count'] ? '0.85' : '0.15' }};"></div>
                                <span class="small text-secondary mt-2">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">Model usage</h2>
                    <p class="small text-secondary mb-4">Submitted analyses by selected inference mode</p>
                    @forelse ($modelUsage as $model)
                        <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                            <span class="fw-semibold">{{ $model['name'] }}</span>
                            <span class="badge rounded-pill text-bg-light border text-dark">{{ number_format($model['total']) }}</span>
                        </div>
                    @empty
                        <div class="text-center text-secondary py-5">No model usage to report yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-7">
            <section class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">Most frequently detected defects</h2>
                    <p class="small text-secondary mb-4">Counts reflect detector output, not ground-truth prevalence.</p>
                    @forelse ($topDefects as $defect)
                        @php($share = $detectionCount > 0 ? $defect->total / $detectionCount * 100 : 0)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between gap-3 small mb-1">
                                <span class="fw-semibold text-break">{{ str_replace('_', ' ', $defect->class_name) }}</span>
                                <span class="text-secondary text-nowrap">{{ number_format($defect->total) }} · {{ number_format($share, 1) }}%</span>
                            </div>
                            <div class="progress" role="progressbar" aria-label="{{ $defect->class_name }} detections" aria-valuenow="{{ round($share) }}" aria-valuemin="0" aria-valuemax="100" style="height: 8px;">
                                <div class="progress-bar bg-primary" style="width: {{ $share }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-secondary py-5">No defect detections have been recorded yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">Risk-level distribution</h2>
                    <p class="small text-secondary mb-4">Risk labels assigned by the current analysis pipeline</p>
                    @foreach (['high' => 'danger', 'medium' => 'warning', 'low' => 'success'] as $risk => $tone)
                        @php($count = (int) $riskCounts->get($risk, 0))
                        <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                            <span class="d-flex align-items-center gap-2"><span class="rounded-circle bg-{{ $tone }}" style="width: 9px; height: 9px;"></span><span class="fw-semibold text-capitalize">{{ $risk }}</span></span>
                            <span class="text-secondary">{{ number_format($count) }} <span class="small">({{ $riskTotal > 0 ? number_format($count / $riskTotal * 100, 1) : '0.0' }}%)</span></span>
                        </div>
                    @endforeach
                    @if ((int) $riskCounts->get('', 0) > 0 || (int) $riskCounts->get(null, 0) > 0)
                        <div class="small text-secondary mt-3">Some detections have no risk label.</div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <section class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1">Benchmarking &amp; interpretation</h2>
                    <p class="text-secondary mb-0">This page reports application activity and model outputs, not precision, recall, or mAP.</p>
                </div>
                <span class="badge rounded-pill text-bg-secondary align-self-start">Test-set metrics not recorded</span>
            </div>
            <hr>
            <p class="small text-secondary mb-0">To compare model accuracy fairly, evaluate YOLO, MobileNet, and Faster R-CNN against the same held-out, labeled dataset and report mAP@0.50, mAP@0.50:0.95, and inference FPS. Align checkpoint class labels before comparing models; detection confidence is not an accuracy score.</p>
        </div>
    </section>
</div>
