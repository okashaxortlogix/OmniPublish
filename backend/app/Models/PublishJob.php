<?php

namespace App\Models;

use App\Events\Publishing\PublishingJobAuthRequired;
use App\Events\Publishing\PublishingJobCancelled;
use App\Events\Publishing\PublishingJobCompleted;
use App\Events\Publishing\PublishingJobFailed;
use App\Events\Publishing\PublishingJobProcessing;
use App\Events\Publishing\PublishingJobProgress;
use App\Events\Publishing\PublishingJobQueued;
use App\Events\Publishing\PublishingJobRetrying;
use App\Events\Publishing\PublishingJobStarted;
use App\Events\Publishing\PublishingJobValidating;
use App\Services\PublishingRetryPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PublishJob extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_SCHEDULED = 'SCHEDULED';
    public const STATUS_VALIDATING = 'VALIDATING';
    public const STATUS_UPLOADING = 'UPLOADING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_RETRYING = 'RETRYING';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const VALID_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_QUEUED, self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_QUEUED => [self::STATUS_SCHEDULED, self::STATUS_VALIDATING, self::STATUS_UPLOADING, self::STATUS_PROCESSING, self::STATUS_PUBLISHED, self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_AUTH_REQUIRED],
        self::STATUS_SCHEDULED => [self::STATUS_QUEUED, self::STATUS_VALIDATING, self::STATUS_CANCELLED],
        self::STATUS_VALIDATING => [self::STATUS_UPLOADING, self::STATUS_PROCESSING, self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_AUTH_REQUIRED, self::STATUS_CANCELLED],
        self::STATUS_UPLOADING => [self::STATUS_PROCESSING, self::STATUS_PUBLISHED, self::STATUS_RETRYING, self::STATUS_FAILED, self::STATUS_AUTH_REQUIRED, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_PUBLISHED, self::STATUS_RETRYING, self::STATUS_FAILED, self::STATUS_AUTH_REQUIRED, self::STATUS_CANCELLED],
        self::STATUS_RETRYING => [self::STATUS_QUEUED, self::STATUS_VALIDATING, self::STATUS_UPLOADING, self::STATUS_PROCESSING, self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_AUTH_REQUIRED, self::STATUS_CANCELLED],
        self::STATUS_AUTH_REQUIRED => [self::STATUS_QUEUED, self::STATUS_VALIDATING],
        self::STATUS_FAILED => [self::STATUS_QUEUED, self::STATUS_VALIDATING],
        self::STATUS_CANCELLED => [self::STATUS_QUEUED],
        self::STATUS_PUBLISHED => [], // Strictly terminal immutable state!
    ];

    protected $fillable = [
        'publish_batch_id',
        'workspace_id',
        'social_account_id',
        'media_asset_id',
        'platform',
        'status',
        'scheduled_at',
        'retry_at',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
        'idempotency_key',
        'correlation_id',
        'destination_config',
        'validation_errors',
        'provider_error',
        'attempts_count',
        'max_attempts',
        'remote_post_id',
        'remote_post_url',
        'upload_session_url',
        'bytes_uploaded',
        'total_bytes',
        'failure_category',
        'user_facing_error_message',
        'internal_error_details',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'retry_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'destination_config' => 'array',
            'validation_errors' => 'array',
            'provider_error' => 'array',
            'attempts_count' => 'integer',
            'max_attempts' => 'integer',
            'bytes_uploaded' => 'integer',
            'total_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PublishJob $job) {
            if (empty($job->correlation_id)) {
                $job->correlation_id = (string) Str::uuid();
            }
            if (empty($job->idempotency_key)) {
                $job->idempotency_key = "job_{$job->workspace_id}_{$job->platform}_" . ($job->social_account_id ?? 'no_account') . '_' . time();
            }
        });
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

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PublishAttempt::class);
    }

    public function canTransitionTo(string $targetStatus): bool
    {
        $current = $this->status ?? self::STATUS_QUEUED;
        if ($current === $targetStatus) {
            return true;
        }

        $allowed = self::VALID_TRANSITIONS[$current] ?? [];
        return in_array($targetStatus, $allowed, true);
    }

    public function transitionTo(string $targetStatus, array $extra = []): void
    {
        $current = $this->status ?? self::STATUS_QUEUED;
        if ($current === $targetStatus) {
            if (!empty($extra)) {
                $this->update($extra);
            }
            return;
        }

        if (!$this->canTransitionTo($targetStatus)) {
            throw new \DomainException(
                "Invalid publishing job state transition from [{$current}] to [{$targetStatus}]."
            );
        }

        // Rule: PUBLISHED must never be optimistic - requires confirmed remote_post_id
        if ($targetStatus === self::STATUS_PUBLISHED) {
            $remoteId = $extra['remote_post_id'] ?? $this->remote_post_id;
            if (empty($remoteId)) {
                throw new \DomainException(
                    "Illegal optimistic transition: State PUBLISHED requires a confirmed remote_post_id."
                );
            }
            $extra['completed_at'] = now();
            $extra['retry_at'] = null;
        }

        // Rule: FAILED must include a failure category
        if ($targetStatus === self::STATUS_FAILED) {
            $cat = $extra['failure_category'] ?? $this->failure_category;
            $extra['failure_category'] = !empty($cat) ? $cat : PublishingRetryPolicy::CATEGORY_UNKNOWN;
            $extra['completed_at'] = now();
        }

        // Rule: CANCELLED sets completed_at
        if ($targetStatus === self::STATUS_CANCELLED) {
            $extra['completed_at'] = now();
            $extra['retry_at'] = null;
        }

        // Rule: Started timestamp tracking
        if (in_array($targetStatus, [self::STATUS_VALIDATING, self::STATUS_UPLOADING], true) && empty($this->started_at)) {
            $extra['started_at'] = now();
        }

        // Rule: Heartbeat tracking on active working states
        if (in_array($targetStatus, [self::STATUS_VALIDATING, self::STATUS_UPLOADING, self::STATUS_PROCESSING], true)) {
            $extra['last_heartbeat_at'] = now();
        }

        $this->update(array_merge(['status' => $targetStatus], $extra));

        // Dispatch standardized canonical event
        $this->dispatchCanonicalEvent($targetStatus);
    }

    public function heartbeat(): void
    {
        $this->update(['last_heartbeat_at' => now()]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    protected function dispatchCanonicalEvent(string $status): void
    {
        try {
            match ($status) {
                self::STATUS_QUEUED => PublishingJobQueued::dispatch($this),
                self::STATUS_VALIDATING => PublishingJobValidating::dispatch($this),
                self::STATUS_UPLOADING => PublishingJobStarted::dispatch($this),
                self::STATUS_PROCESSING => PublishingJobProcessing::dispatch($this),
                self::STATUS_RETRYING => PublishingJobRetrying::dispatch($this),
                self::STATUS_PUBLISHED => PublishingJobCompleted::dispatch($this),
                self::STATUS_FAILED => PublishingJobFailed::dispatch($this),
                self::STATUS_AUTH_REQUIRED => PublishingJobAuthRequired::dispatch($this),
                self::STATUS_CANCELLED => PublishingJobCancelled::dispatch($this),
                default => null,
            };
        } catch (\Throwable $e) {
            // Event delivery should not abort main state transition
            \Illuminate\Support\Facades\Log::warning("PublishJob event dispatch error: {$e->getMessage()}");
        }
    }
}
