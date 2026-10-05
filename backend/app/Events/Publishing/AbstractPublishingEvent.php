<?php

namespace App\Events\Publishing;

use App\Models\PublishJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class AbstractPublishingEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $workspaceId;
    public readonly string $jobId;
    public readonly string $platform;
    public readonly ?string $mediaId;
    public readonly ?string $correlationId;
    public readonly string $timestamp;
    public readonly array $metadata;

    public function __construct(PublishJob $job, array $metadata = [])
    {
        $this->workspaceId = (string) $job->workspace_id;
        $this->jobId = (string) $job->id;
        $this->platform = (string) $job->platform;
        $this->mediaId = $job->media_asset_id ?? $job->publishBatch?->contentItem?->media_asset_id;
        $this->correlationId = $job->correlation_id;
        $this->timestamp = now()->toIso8601String();
        $this->metadata = $metadata;
    }

    /**
     * Get safe array representation without sensitive secrets.
     */
    public function toPayload(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'job_id' => $this->jobId,
            'platform' => $this->platform,
            'media_id' => $this->mediaId,
            'correlation_id' => $this->correlationId,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }
}
