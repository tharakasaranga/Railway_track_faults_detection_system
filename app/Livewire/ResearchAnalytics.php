<?php

namespace App\Livewire;

use App\Models\Detection;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ResearchAnalytics extends Component
{
    public function render()
    {
        $today = now()->startOfDay();
        $trendStart = $today->copy()->subDays(6);
        $dailyVideoCounts = Video::query()
            ->whereBetween('created_at', [$trendStart, $today->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as analysis_date, COUNT(*) as total')
            ->groupBy('analysis_date')
            ->pluck('total', 'analysis_date');

        $dailyTrend = collect(range(6, 0))->map(function (int $daysAgo) use ($today, $dailyVideoCounts) {
            $date = $today->copy()->subDays($daysAgo);
            $key = $date->toDateString();

            return [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'count' => (int) $dailyVideoCounts->get($key, 0),
            ];
        });
        $maxDailyCount = max(1, (int) $dailyTrend->max('count'));

        $modelUsage = Video::query()
            ->selectRaw('model_type, COUNT(*) as total')
            ->groupBy('model_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => match ($row->model_type) {
                    'yolov8' => 'YOLO',
                    'mobilenet' => 'MobileNet',
                    'fasterrcnn' => 'Faster R-CNN',
                    'ensemble' => 'Ensemble',
                    default => ucfirst((string) $row->model_type),
                },
                'total' => (int) $row->total,
            ]);

        $topDefects = Detection::query()
            ->selectRaw('class_name, COUNT(*) as total')
            ->groupBy('class_name')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $riskCounts = Detection::query()
            ->selectRaw('risk_level, COUNT(*) as total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level');

        $statusCounts = Video::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $totalVideos = Video::count();
        $completedVideos = (int) $statusCounts->get('completed', 0);
        $detectionCount = Detection::count();

        return view('livewire.research-analytics', [
            'totalVideos' => $totalVideos,
            'completedVideos' => $completedVideos,
            'completionRate' => $totalVideos > 0
                ? round($completedVideos / $totalVideos * 100, 1)
                : 0,
            'detectionCount' => $detectionCount,
            'averageConfidence' => $detectionCount > 0
                ? round((float) Detection::avg('confidence') * 100, 1)
                : null,
            'dailyTrend' => $dailyTrend,
            'maxDailyCount' => $maxDailyCount,
            'modelUsage' => $modelUsage,
            'topDefects' => $topDefects,
            'riskCounts' => $riskCounts,
            'statusCounts' => $statusCounts,
            'riskTotal' => (int) $riskCounts->sum(),
            'periodStart' => $trendStart->format('M j'),
            'periodEnd' => $today->format('M j, Y'),
        ])->layout('layouts.app');
    }
}
