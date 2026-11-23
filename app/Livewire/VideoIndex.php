<?php

namespace App\Livewire;

use App\Models\Video;
use Livewire\Component;

class VideoIndex extends Component
{
    public function render()
    {
        return view('livewire.video-index', [
            'videos' => Video::with('modelWeight')
                ->latest()
                ->get(),
        ])->layout('layouts.app');
    }
}