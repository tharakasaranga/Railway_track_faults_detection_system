<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_catalog_lists_all_three_model_families_without_registered_weights(): void
    {
        $this->get(route('models.index'))
            ->assertOk()
            ->assertSee('YOLO')
            ->assertSee('MobileNet')
            ->assertSee('Faster R-CNN')
            ->assertSee('No checkpoint is registered')
            ->assertSee('id="theme-toggle"', false)
            ->assertSee('Enable dark mode');
    }

    public function test_research_analytics_uses_recorded_video_and_detection_data(): void
    {
        $video = Video::query()->create([
            'original_name' => 'track-inspection.mp4',
            'stored_name' => 'track-inspection.mp4',
            'input_path' => 'videos/track-inspection.mp4',
            'status' => 'completed',
            'model_type' => 'yolov8',
        ]);

        Detection::query()->create([
            'video_id' => $video->id,
            'class_name' => 'Rail_Surface_Defect',
            'confidence' => 0.875,
            'x1' => 10,
            'y1' => 20,
            'x2' => 30,
            'y2' => 40,
            'risk_level' => 'medium',
        ]);

        $this->get(route('research.analytics'))
            ->assertOk()
            ->assertSee('1')
            ->assertSee('87.5%')
            ->assertSee('YOLO')
            ->assertSee('Rail Surface Defect')
            ->assertSee('Test-set metrics not recorded');
    }
}
