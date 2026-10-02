<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\Workspace;
use App\Services\PublishingService;
use App\Platforms\Services\PlatformManager;
use Illuminate\Http\Request;

class PublishingController extends Controller
{
    public function __construct(
        protected PublishingService $publishingService,
        protected PlatformManager $platformManager
    ) {}

    public function index(Request $request)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = PublishBatch::with(['publishJobs.socialAccount', 'contentItem.mediaAsset'])->latest();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(20),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'mediaAssetId' => 'nullable|string',
            'globalMetadata' => 'required|array',
            'globalMetadata.title' => 'required|string|max:100',
            'globalMetadata.description' => 'nullable|string|max:5000',
            'globalMetadata.caption' => 'nullable|string|max:2200',
            'globalMetadata.hashtags' => 'nullable|array',
            'globalMetadata.tags' => 'nullable|array',
            'globalMetadata.visibility' => 'nullable|string',
            'platformOverrides' => 'nullable|array',
            'targetPlatforms' => 'required|array|min:1',
            'scheduledFor' => 'nullable|string',
        ]);

        $batch = $this->publishingService->createBatch(
            workspaceId: Workspace::resolveId($validated['workspaceId']) ?? $validated['workspaceId'],
            userId: null,
            mediaAssetId: $validated['mediaAssetId'] ?? null,
            globalMetadata: $validated['globalMetadata'],
            platformOverrides: $validated['platformOverrides'] ?? [],
            targetPlatforms: $validated['targetPlatforms'],
            scheduledFor: $validated['scheduledFor'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $batch,
        ], 201);
    }

    public function show(string $id)
    {
        $batch = PublishBatch::with(['publishJobs.socialAccount', 'contentItem.mediaAsset'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $batch,
        ]);
    }

    public function retryJob(Request $request, string $jobId)
    {
        $job = PublishJob::findOrFail($jobId);

        $retriedJob = $this->publishingService->retryJob(
            workspaceId: $job->workspace_id,
            jobId: $jobId
        );

        return response()->json([
            'success' => true,
            'data' => $retriedJob,
        ]);
    }

    public function validateContent(Request $request)
    {
        $validated = $request->validate([
            'globalMetadata' => 'required|array',
            'platformOverrides' => 'nullable|array',
            'targetPlatforms' => 'required|array',
        ]);

        $issues = [];
        foreach ($validated['targetPlatforms'] as $platform) {
            $adapter = $this->platformManager->get($platform);
            $platformIssues = $adapter->validate(
                $validated['globalMetadata'],
                $validated['platformOverrides'] ?? [],
                null
            );
            $issues[$platform] = $platformIssues;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'isValid' => empty(array_filter($issues, fn($p) => !empty($p))),
                'issues' => $issues,
            ]
        ]);
    }
}
