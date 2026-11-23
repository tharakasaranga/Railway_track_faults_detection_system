<?php

use App\Livewire\Dashboard;
use App\Livewire\ModelCatalog;
use App\Livewire\ResearchAnalytics;
use App\Livewire\VideoIndex;
use App\Livewire\VideoShow;
use App\Livewire\VideoUpload;
use App\Models\Detection;
use App\Models\Video;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', Dashboard::class)
    ->name('dashboard');

Route::get('/videos', VideoIndex::class)
    ->name('videos.index');

Route::get('/videos/upload', VideoUpload::class)
    ->name('videos.upload');

Route::get('/models', ModelCatalog::class)
    ->name('models.index');

Route::get('/research/analytics', ResearchAnalytics::class)
    ->name('research.analytics');

Route::get('/videos/{video}', VideoShow::class)
    ->name('videos.show');

Route::get('/videos/{video}/output', function (Video $video) {

    abort_unless(
        $video->status === 'completed'
        && $video->output_path,
        404
    );

    $outputPath = Storage::disk('local')
        ->path($video->output_path);

    abort_unless(is_file($outputPath), 404);

    return response()->file($outputPath, [
        'Content-Type' => 'video/mp4',
        'Accept-Ranges' => 'bytes',
    ]);

})->name('videos.output');

Route::get('/videos/{video}/detections/{detection}/image', function (Video $video, Detection $detection) {
    abort_unless(
        $video->status === 'completed'
        && $detection->video_id === $video->id
        && $detection->image_path
        && str_starts_with($detection->image_path, 'outputs/'),
        404
    );

    $outputDirectory = realpath(Storage::disk('local')->path('outputs'));
    $imagePath = realpath(Storage::disk('local')->path($detection->image_path));
    abort_unless(
        $outputDirectory
        && $imagePath
        && str_starts_with($imagePath, rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
        && is_file($imagePath),
        404
    );

    return response()->file($imagePath, [
        'Content-Type' => 'image/jpeg',
    ]);
})->name('videos.detections.image');
