<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingJob extends Model
{
    protected $fillable = [
        'video_id',
        'status',
        'started_at',
        'completed_at',
        'processing_time',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'processing_time' => 'decimal:2',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}