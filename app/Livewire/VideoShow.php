<?php

namespace App\Livewire;

use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class VideoShow extends Component
{
    use WithPagination;

    public Video $video;

    public function mount(Video $video): void
    {
        $this->video = $video->load(['modelWeight', 'processingJob']);
    }

    public function stopAnalysis(): void
    {
        DB::transaction(function (): void {
            $video = Video::query()->lockForUpdate()->findOrFail($this->video->id);

            if (! in_array($video->status, ['pending', 'processing'], true)) {
                return;
            }

            $timestamp = now();
            $video->update([
                'status' => 'cancelled',
                'stage' => 'cancelled',
                'error_message' => 'Analysis was stopped by the user.',
            ]);
            $video->processingJob()->update([
                'status' => 'cancelled',
                'completed_at' => $timestamp,
                'error_message' => 'Analysis was stopped by the user.',
            ]);
        });

        $this->video->refresh()->load('processingJob');
    }

    public function render()
    {
        // Refresh the model when Livewire polling is active.
        if (in_array($this->video->status, ['pending', 'processing'])) {
            $this->video->refresh();
            $this->video->load([
                'processingJob',
            ]);
        }

        $detectionGroups = $this->video->detections()
            ->select('class_name', DB::raw('COUNT(*) as detection_count'))
            ->groupBy('class_name')
            ->orderBy('class_name')
            ->pluck('detection_count', 'class_name');
        $detectionCount = (int) $detectionGroups->sum();
        $uniqueClasses = $detectionGroups->count();
        $averageConfidence = (float) ($this->video->detections()->avg('confidence') ?? 0) * 100;
        $detectionImages = $this->video->detections()
            ->whereNotNull('image_path')
            ->orderBy('frame_number')
            ->orderBy('id')
            ->paginate(12);

        return view('livewire.video-show', [
            'detectionGroups' => $detectionGroups,
            'detectionCount' => $detectionCount,
            'uniqueClasses' => $uniqueClasses,
            'averageConfidence' => round($averageConfidence, 1),
            'detectionImages' => $detectionImages,
        ])
            ->layout('layouts.app');
    }
}
