<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Detection extends Model
{
    protected $fillable = [
        'video_id',
        'frame_number',
        'class_name',
        'class_id',
        'confidence',
        'x1',
        'y1',
        'x2',
        'y2',
        'risk_level',
        'image_path',
    ];

    protected $casts = [
        'frame_number' => 'integer',
        'class_id' => 'integer',
        'confidence' => 'float',
        'x1' => 'float',
        'y1' => 'float',
        'x2' => 'float',
        'y2' => 'float',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
