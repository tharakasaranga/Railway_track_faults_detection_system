<?php

namespace Tests\Feature;

use App\Jobs\ProcessRailwayVideo;
use App\Livewire\VideoUpload;
use App\Models\ModelWeight;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadTest extends TestCase
{
    public function test_best_weight_resolves_to_the_railway_checkpoint(): void
    {
        $this->assertSame(
            base_path('python/models/best/best.pt'),
            ModelWeight::resolveFilePath('best.pt')
        );
    }

    public function test_it_creates_video_and_processing_job_when_uploading_a_valid_video(): void
    {
        Storage::fake('local');
        Queue::fake();

        $modelPath = tempnam(sys_get_temp_dir(), 'railway-model-');
        file_put_contents($modelPath, 'test weights');

        $modelWeight = ModelWeight::query()->create([
            'name' => 'Best Model',
            'filename' => 'best.pt',
            'path' => $modelPath,
            'architecture' => 'YOLOv8m',
            'version' => '1.0',
            'is_active' => true,
            'is_default' => true,
        ]);

        $component = new VideoUpload;
        $component->modelWeightId = $modelWeight->id;
        $component->confidence = 0.25;
        $component->videoFile = UploadedFile::fake()->create('demo.mp4', 150, 'video/mp4');

        try {
            $component->startAnalysis();
        } finally {
            @unlink($modelPath);
        }

        $this->assertDatabaseHas('videos', [
            'model_weight_id' => $modelWeight->id,
            'status' => 'pending',
            'original_name' => 'demo.mp4',
        ]);

        $this->assertDatabaseHas('processing_jobs', [
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessRailwayVideo::class, function ($job) {
            return $job->videoId > 0;
        });
    }

    public function test_it_queues_ensemble_analysis_without_a_single_selected_weight(): void
    {
        Storage::fake('local');
        Queue::fake();

        $component = new VideoUpload;
        $component->modelType = 'ensemble';
        $component->modelWeightId = null;
        $component->confidence = 0.25;
        $component->videoFile = UploadedFile::fake()->create('ensemble.mp4', 150, 'video/mp4');

        $component->startAnalysis();

        $this->assertDatabaseHas('videos', [
            'model_type' => 'ensemble',
            'model_weight_id' => null,
            'model_name' => 'Ensemble (All Models)',
            'original_name' => 'ensemble.mp4',
        ]);
        Queue::assertPushed(ProcessRailwayVideo::class);
    }
}
