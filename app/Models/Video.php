<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    protected $fillable = [
        'original_name',
        'stored_name',
        'input_path',
        'output_path',
        'status',
        'stage',
        'progress',
        'model_weight_id',
        'model_type',
        'model_name',
        'file_size',
        'confidence',
        'duration',
        'total_frames',
        'processed_frames',
        'processing_time',
        'fps',
        'eta_seconds',
        'roi_review_frames',
        'error_message',
    ];

    protected $casts = [
        'duration' => 'float',
        'processing_time' => 'float',
        'confidence' => 'float',
        'file_size' => 'integer',
        'total_frames' => 'integer',
        'processed_frames' => 'integer',
        'fps' => 'float',
        'eta_seconds' => 'integer',
        'roi_review_frames' => 'integer',
        'progress' => 'float',
    ];

    public function modelWeight(): BelongsTo
    {
        return $this->belongsTo(ModelWeight::class);
    }

    public function processingJob(): HasOne
    {
        return $this->hasOne(ProcessingJob::class);
    }

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }
}
