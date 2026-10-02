<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'publish_job_id',
        'attempt_number',
        'status',
        'error_category',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempt_number' => 'integer',
        ];
    }

    public function publishJob(): BelongsTo
    {
        return $this->belongsTo(PublishJob::class);
    }
}
