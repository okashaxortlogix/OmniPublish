<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'media_asset_id',
        'global_metadata',
        'platform_overrides',
        'target_platforms',
    ];

    protected function casts(): array
    {
        return [
            'global_metadata' => 'array',
            'platform_overrides' => 'array',
            'target_platforms' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function publishBatches(): HasMany
    {
        return $this->hasMany(PublishBatch::class);
    }
}
