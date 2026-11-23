<?php

namespace Database\Seeders;

use App\Models\ModelWeight;
use Illuminate\Database\Seeder;

class ModelWeightSeeder extends Seeder
{
    public function run(): void
    {
        $bestPath = ModelWeight::resolveFilePath('best.pt') ?? base_path('python/best.pt');
        $lastPath = ModelWeight::resolveFilePath('last.pt') ?? base_path('python/last.pt');
        $mobileNetPath = base_path('python/models/mobileNet/best_mobilenet_ssd.pth');
        $fasterRcnnPath = base_path('python/models/fasterRCnn/best_model.pth');

        ModelWeight::updateOrCreate(
            ['filename' => 'best.pt'],
            [
                'name' => 'Best Model',
                'path' => $bestPath,
                'model_type' => 'yolov8',
                'architecture' => 'YOLOv8m',
                'version' => '1.0',
                'description' => 'Best-performing trained model checkpoint.',
                'is_active' => is_file($bestPath) && filesize($bestPath) > 0,
                'is_default' => true,
            ]
        );

        ModelWeight::updateOrCreate(
            ['filename' => 'last.pt'],
            [
                'name' => 'Last Checkpoint',
                'path' => $lastPath,
                'model_type' => 'yolov8',
                'architecture' => 'YOLOv8m',
                'version' => '1.0',
                'description' => 'Last saved training checkpoint.',
                'is_active' => is_file($lastPath) && filesize($lastPath) > 0,
                'is_default' => false,
            ]
        );

        ModelWeight::updateOrCreate(
            ['filename' => 'best_mobilenet_ssd.pth'],
            [
                'name' => 'MobileNet-SSDLite',
                'path' => $mobileNetPath,
                'model_type' => 'mobilenet',
                'architecture' => 'MobileNetV3 SSDLite',
                'version' => '1.0',
                'description' => 'Trained MobileNet-SSDLite checkpoint.',
                'is_active' => is_file($mobileNetPath) && filesize($mobileNetPath) > 0,
                'is_default' => true,
            ]
        );

        ModelWeight::updateOrCreate(
            ['filename' => 'best_model.pth'],
            [
                'name' => 'Faster R-CNN',
                'path' => $fasterRcnnPath,
                'model_type' => 'fasterrcnn',
                'architecture' => 'Faster R-CNN ResNet-50 FPN',
                'version' => '1.0',
                'description' => 'Trained Faster R-CNN checkpoint.',
                'is_active' => is_file($fasterRcnnPath) && filesize($fasterRcnnPath) > 0,
                'is_default' => true,
            ]
        );
    }
}
