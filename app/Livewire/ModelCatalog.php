<?php

namespace App\Livewire;

use App\Models\ModelWeight;
use App\Models\Video;
use Livewire\Component;

class ModelCatalog extends Component
{
    private const MODEL_PROFILES = [
        'yolov8' => [
            'name' => 'YOLO',
            'architecture' => 'YOLOv8m',
            'category' => 'Single-stage detector',
            'summary' => 'Detects and classifies track defects in one pass, making it a strong choice for fast video analysis.',
            'input' => '640 px inference image size',
            'strengths' => ['Fast, one-pass inference', 'Useful for continuous video review', 'Bounding boxes include class and confidence'],
            'tradeoff' => 'Detection quality depends on the trained checkpoint and confidence threshold.',
            'icon' => '⚡',
            'tone' => 'primary',
        ],
        'mobilenet' => [
            'name' => 'MobileNet',
            'architecture' => 'MobileNetV3 Large + SSDLite',
            'category' => 'Lightweight single-stage detector',
            'summary' => 'Pairs a compact MobileNetV3 backbone with SSDLite to provide a lighter-weight detection option.',
            'input' => '320 × 320 px model input',
            'strengths' => ['Compact MobileNetV3 backbone', 'Designed for efficient inference', 'Returns class, confidence, and bounding box'],
            'tradeoff' => 'A smaller input and lightweight backbone can trade detail for efficiency.',
            'icon' => '📱',
            'tone' => 'success',
        ],
        'fasterrcnn' => [
            'name' => 'Faster R-CNN',
            'architecture' => 'Faster R-CNN ResNet-50 FPN v2',
            'category' => 'Two-stage detector',
            'summary' => 'Uses region proposals followed by object classification and box refinement for a detailed detection pipeline.',
            'input' => 'Torchvision-managed image scaling',
            'strengths' => ['Region-proposal based detection', 'ResNet-50 feature backbone with FPN', 'Useful as a comparison to single-stage models'],
            'tradeoff' => 'The two-stage pipeline generally prioritizes detailed detection over minimum inference cost.',
            'icon' => '🔎',
            'tone' => 'warning',
        ],
    ];

    public function render()
    {
        $weights = ModelWeight::query()
            ->orderBy('model_type')
            ->orderByDesc('is_default')
            ->get()
            ->groupBy('model_type');

        $usage = Video::query()
            ->selectRaw('model_type, COUNT(*) as video_count')
            ->groupBy('model_type')
            ->pluck('video_count', 'model_type');

        $models = collect(self::MODEL_PROFILES)->map(function (array $profile, string $type) use ($weights, $usage) {
            $profile['type'] = $type;
            $profile['weights'] = $weights->get($type, collect());
            $profile['video_count'] = (int) $usage->get($type, 0);

            return $profile;
        });

        return view('livewire.model-catalog', [
            'models' => $models,
            'ensembleCount' => (int) $usage->get('ensemble', 0),
        ])->layout('layouts.app');
    }
}
