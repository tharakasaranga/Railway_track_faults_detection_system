<?php

namespace App\Livewire;

use App\Models\Detection;
use App\Models\Video;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.dashboard', [
            'totalVideos' => Video::count(),

            'completedVideos' => Video::where(
                'status',
                'completed'
            )->count(),

            'processingVideos' => Video::whereIn(
                'status',
                ['pending', 'processing']
            )->count(),

            'totalDetections' => Detection::count(),

            'recentVideos' => Video::latest()
                ->take(5)
                ->get(),
        ])->layout('layouts.app');
    }
}