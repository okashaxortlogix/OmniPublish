<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'filename',
        'original_name',
        'mime_type',
        'size_bytes',
        'storage_path',
        'thumbnail_path',
        'duration_seconds',
        'width',
        'height',
        'aspect_ratio',
        'codec',
        'status',
        'error_message',
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
    ];

    public function getUrlAttribute(): ?string
    {
        if (!$this->storage_path) return null;
        return Storage::disk('public')->url($this->storage_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) return null;
        return Storage::disk('public')->url($this->thumbnail_path);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }
}
