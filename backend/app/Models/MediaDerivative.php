<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaDerivative extends Model
{
    use HasUuids;

    protected $fillable = [
        'media_asset_id',
        'workspace_id',
        'derivative_type',
        'target_platform',
        'storage_path',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'bitrate',
        'duration_seconds',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bitrate' => 'integer',
        'duration_seconds' => 'integer',
    ];

    protected $appends = [
        'url',
    ];

    public function getUrlAttribute(): ?string
    {
        if (!$this->storage_path) return null;
        if (str_starts_with($this->storage_path, 'http://') || str_starts_with($this->storage_path, 'https://')) {
            return $this->storage_path;
        }
        $disk = config('filesystems.default', 'public');
        return Storage::disk($disk)->url($this->storage_path);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
