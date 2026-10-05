<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

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
        'bitrate',
        'frame_rate',
        'video_codec',
        'audio_codec',
        'audio_channels',
        'audio_sample_rate',
        'pixel_format',
        'container_format',
        'status',
        'processing_stage',
        'processing_started_at',
        'processing_completed_at',
        'error_message',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bitrate' => 'integer',
        'audio_channels' => 'integer',
        'audio_sample_rate' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
    ];

    /**
     * Allowed State Transitions Matrix
     */
    protected const VALID_TRANSITIONS = [
        'UPLOAD_AUTHORIZED' => ['QUEUED', 'FAILED', 'DELETED'],
        'QUEUED'            => ['PROCESSING', 'CANCELLED', 'FAILED', 'DELETED'],
        'PROCESSING'        => ['READY', 'FAILED', 'CANCELLED'],
        'READY'             => ['DELETED'],
        'FAILED'            => ['QUEUED', 'DELETED'],
        'CANCELLED'         => ['QUEUED', 'DELETED'],
        'DELETED'           => [],
    ];

    public function transitionTo(string $targetStatus, ?string $stage = null, ?string $error = null): void
    {
        $current = $this->status ?? 'UPLOAD_AUTHORIZED';

        if (!$this->canTransitionTo($targetStatus)) {
            throw new InvalidArgumentException(
                "Invalid media state transition from [{$current}] to [{$targetStatus}]."
            );
        }

        $updates = ['status' => $targetStatus];

        if ($stage !== null) {
            $updates['processing_stage'] = $stage;
        }

        if ($targetStatus === 'PROCESSING') {
            $updates['processing_started_at'] = now();
            $updates['error_message'] = null;
        }

        if ($targetStatus === 'READY') {
            $updates['processing_completed_at'] = now();
            $updates['processing_stage'] = 'READY';
            $updates['error_message'] = null;
        }

        if ($targetStatus === 'FAILED') {
            $updates['processing_completed_at'] = now();
            $updates['processing_stage'] = 'FAILED';
            if ($error !== null) {
                $updates['error_message'] = $error;
            }
        }

        $this->update($updates);
    }

    public function canTransitionTo(string $targetStatus): bool
    {
        $current = $this->status ?? 'UPLOAD_AUTHORIZED';
        if ($current === $targetStatus) {
            return true;
        }

        $allowed = self::VALID_TRANSITIONS[$current] ?? [];
        return in_array($targetStatus, $allowed, true);
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->storage_path) return null;
        if (str_starts_with($this->storage_path, 'http://') || str_starts_with($this->storage_path, 'https://')) {
            return $this->storage_path;
        }
        $disk = config('filesystems.default', 'public');
        return Storage::disk($disk)->url($this->storage_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) return null;
        if (str_starts_with($this->thumbnail_path, 'http://') || str_starts_with($this->thumbnail_path, 'https://')) {
            return $this->thumbnail_path;
        }
        $disk = config('filesystems.default', 'public');
        return Storage::disk($disk)->url($this->thumbnail_path);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(MediaDerivative::class);
    }
}
