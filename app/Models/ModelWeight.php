<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelWeight extends Model
{
    protected $fillable = [
        'name',
        'filename',
        'path',
        'model_type',
        'architecture',
        'version',
        'description',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function resolvedPath(): ?string
    {
        $configuredPath = trim((string) $this->path);
        $filename = basename((string) $this->filename);
        if ($filename === '') {
            return null;
        }

        return self::resolveFilePath($filename, $configuredPath);
    }

    public static function resolveFilePath(string $filename, ?string $fallbackPath = null): ?string
    {
        $filename = basename($filename);
        $candidates = [
            base_path('python/models/best/'.$filename),
            base_path('python/'.$filename),
            base_path('python/models/'.$filename),
            $fallbackPath,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
