<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublishJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'publish_batch_id',
        'workspace_id',
        'social_account_id',
        'platform',
        'status',
        'attempts_count',
        'max_attempts',
        'remote_post_id',
        'remote_post_url',
        'failure_category',
        'user_facing_error_message',
        'internal_error_details',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts_count' => 'integer',
            'max_attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function publishBatch(): BelongsTo
    {
        return $this->belongsTo(PublishBatch::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PublishAttempt::class);
    }
}
