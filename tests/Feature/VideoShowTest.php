<?php

namespace Tests\Feature;

use App\Livewire\VideoShow;
use App\Models\Detection;
use App\Models\ProcessingJob;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VideoShowTest extends TestCase
{
    public function test_it_displays_and_serves_saved_defect_images_for_a_completed_video(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('outputs/gallery_annotated.mp4', 'annotated video');
        Storage::disk('local')->put(
            'outputs/gallery_annotated.mp4.detections/detection_00000001.jpg',
            'jpeg image'
        );

        $video = Video::query()->create([
            'original_name' => 'gallery.mp4',
            'stored_name' => 'gallery.mp4',
            'input_path' => 'videos/gallery.mp4',
            'output_path' => 'outputs/gallery_annotated.mp4',
            'status' => 'completed',
        ]);
        $detection = Detection::query()->create([
            'video_id' => $video->id,
            'frame_number' => 3,
            'class_name' => 'rail_crack',
            'confidence' => 0.91,
            'x1' => 10,
            'y1' => 20,
            'x2' => 50,
            'y2' => 60,
            'risk_level' => 'high',
            'image_path' => 'outputs/gallery_annotated.mp4.detections/detection_00000001.jpg',
        ]);

        $imageUrl = route('videos.detections.image', [$video, $detection]);

        $this->get(route('videos.show', $video))
            ->assertOk()
            ->assertSee('Defect Image Gallery')
            ->assertSee('1 image')
            ->assertSee('rail crack')
            ->assertSee('Frame 4')
            ->assertSee($imageUrl);

        $this->get($imageUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->assertSame(
            'jpeg image',
            Storage::disk('local')->get('outputs/gallery_annotated.mp4.detections/detection_00000001.jpg')
        );
    }

    public function test_gallery_next_and_previous_buttons_change_the_detection_page(): void
    {
        $video = Video::query()->create([
            'original_name' => 'many-defects.mp4',
            'stored_name' => 'many-defects.mp4',
            'input_path' => 'videos/many-defects.mp4',
            'output_path' => 'outputs/many-defects_annotated.mp4',
            'status' => 'completed',
        ]);

        foreach (range(1, 13) as $number) {
            Detection::query()->create([
                'video_id' => $video->id,
                'frame_number' => $number,
                'class_name' => $number === 1 ? 'first_defect' : ($number === 13 ? 'final_defect' : 'track_defect'),
                'confidence' => 0.9,
                'x1' => 10,
                'y1' => 20,
                'x2' => 50,
                'y2' => 60,
                'image_path' => sprintf('outputs/many-defects_annotated.mp4.detections/detection_%08d.jpg', $number),
            ]);
        }

        Livewire::test(VideoShow::class, ['video' => $video])
            ->assertSee('Page 1 of 2')
            ->assertSee('first defect')
            ->assertDontSee('final defect')
            ->call('nextPage')
            ->assertSee('Page 2 of 2')
            ->assertDontSee('first defect')
            ->assertSee('final defect')
            ->call('previousPage')
            ->assertSee('Page 1 of 2')
            ->assertSee('first defect')
            ->assertDontSee('final defect');
    }

    public function test_it_stops_pending_or_processing_analysis(): void
    {
        foreach (['pending', 'processing'] as $status) {
            $video = Video::query()->create([
                'original_name' => $status.'.mp4',
                'stored_name' => $status.'.mp4',
                'input_path' => 'videos/'.$status.'.mp4',
                'status' => $status,
                'stage' => $status === 'pending' ? 'queued' : 'processing',
            ]);
            ProcessingJob::query()->create([
                'video_id' => $video->id,
                'status' => $status === 'pending' ? 'queued' : 'running',
            ]);

            Livewire::test(VideoShow::class, ['video' => $video])
                ->call('stopAnalysis')
                ->assertHasNoErrors();

            $this->assertDatabaseHas('videos', [
                'id' => $video->id,
                'status' => 'cancelled',
                'stage' => 'cancelled',
            ]);
            $this->assertDatabaseHas('processing_jobs', [
                'video_id' => $video->id,
                'status' => 'cancelled',
            ]);
        }
    }
}
