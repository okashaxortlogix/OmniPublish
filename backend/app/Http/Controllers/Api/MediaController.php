<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Models\AuditEvent;
use App\Services\Contracts\MediaStorageInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = MediaAsset::latest();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * Presigned Upload Authorization Endpoint
     * Phase 1.9 Direct Upload Pipeline
     */
    public function authorizeUpload(Request $request, MediaStorageInterface $storageService)
    {
        $request->validate([
            'workspaceId' => 'nullable|string',
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string|in:video/mp4,video/quicktime,video/webm,video/x-matroska',
            'size_bytes' => 'required|integer|min:1|max:524288000', // 500MB max limit
        ]);

        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->input('workspaceId');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        if (!$workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'Valid workspace is required for upload authorization.',
            ], 403);
        }

        $mediaId = (string) Str::uuid();
        $filename = $request->input('filename');
        $mimeType = $request->input('mime_type');
        $sizeBytes = (int) $request->input('size_bytes');

        // Generate tenant-scoped, randomized storage path and authorization token/url
        $authorization = $storageService->generateUploadAuthorization(
            $workspaceId,
            $mediaId,
            $filename,
            $mimeType,
            $sizeBytes
        );

        // Pre-create MediaAsset in UPLOAD_AUTHORIZED state
        $media = MediaAsset::create([
            'id' => $mediaId,
            'workspace_id' => $workspaceId,
            'filename' => basename($authorization['storage_path']),
            'original_name' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'storage_path' => $authorization['storage_path'],
            'thumbnail_path' => null,
            'status' => 'UPLOAD_AUTHORIZED',
        ]);

        AuditEvent::create([
            'workspace_id' => $workspaceId,
            'action' => 'MEDIA_UPLOAD_AUTHORIZED',
            'resource_type' => 'MediaAsset',
            'resource_id' => $media->id,
            'metadata' => [
                'filename' => $filename,
                'storage_path' => $authorization['storage_path'],
                'size_bytes' => $sizeBytes,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'media_id' => $media->id,
                'upload_url' => $authorization['upload_url'],
                'method' => $authorization['method'],
                'headers' => $authorization['headers'],
                'storage_path' => $authorization['storage_path'],
                'expires_at' => $authorization['expires_at'],
                'status' => $media->status,
            ],
        ], 201);
    }

    /**
     * Local direct content stream receiver (for local disk testing without AWS S3)
     */
    public function directContent(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = MediaAsset::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $media = $query->findOrFail($id);

        $disk = config('filesystems.default', 'public');
        
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            Storage::disk($disk)->put($media->storage_path, file_get_contents($file->getRealPath()));
        } else {
            $content = $request->getContent();
            Storage::disk($disk)->put($media->storage_path, $content);
        }

        return response()->json([
            'success' => true,
            'message' => 'Direct content uploaded successfully to storage path.',
            'storage_path' => $media->storage_path,
        ]);
    }

    /**
     * Confirm Upload & Backend Object Verification
     * Phase 1.10 Media Verification
     */
    public function confirmUpload(Request $request, MediaStorageInterface $storageService)
    {
        $request->validate([
            'media_id' => 'required|uuid',
            'workspaceId' => 'nullable|string',
        ]);

        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->input('workspaceId');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $media = MediaAsset::where('id', $request->input('media_id'))->first();

        if (!$media) {
            return response()->json([
                'success' => false,
                'message' => 'Media record not found.',
            ], 404);
        }

        // Multi-tenant security check: Ensure caller workspace matches media asset workspace
        if ($workspaceId && $media->workspace_id !== $workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Media asset belongs to a different workspace.',
            ], 403);
        }

        if ($media->status === 'READY') {
            return response()->json([
                'success' => true,
                'data' => $media,
            ]);
        }

        // Verify storage object physically exists on target disk
        $verification = $storageService->verifyUploadedObject(
            $media->storage_path,
            $media->size_bytes,
            $media->mime_type
        );

        if (!$verification['valid']) {
            $media->update([
                'status' => 'FAILED',
                'error_message' => $verification['error'] ?? 'Storage object verification failed.',
            ]);

            AuditEvent::create([
                'workspace_id' => $media->workspace_id,
                'action' => 'MEDIA_VERIFICATION_FAILED',
                'resource_type' => 'MediaAsset',
                'resource_id' => $media->id,
                'metadata' => [
                    'storage_path' => $media->storage_path,
                    'error' => $verification['error'],
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => $verification['error'] ?? 'Storage verification failed.',
            ], 422);
        }

        // Transition to QUEUED and dispatch asynchronous video processing pipeline
        $media->transitionTo('QUEUED', 'QUEUED');
        $media->update([
            'size_bytes' => $verification['actual_size'],
        ]);

        \App\Jobs\ProcessMediaAssetJob::dispatch($media->id);

        AuditEvent::create([
            'workspace_id' => $media->workspace_id,
            'action' => 'MEDIA_UPLOAD_CONFIRMED',
            'resource_type' => 'MediaAsset',
            'resource_id' => $media->id,
            'metadata' => [
                'storage_path' => $media->storage_path,
                'actual_size' => $verification['actual_size'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $media->fresh(['derivatives']),
        ]);
    }

    /**
     * Reprocess a Failed Media Asset
     */
    public function reprocess(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $media = MediaAsset::where('id', $id)->first();

        if (!$media) {
            return response()->json([
                'success' => false,
                'message' => 'Media asset not found.',
            ], 404);
        }

        if ($workspaceId && $media->workspace_id !== $workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Access to media from another workspace denied.',
            ], 403);
        }

        if (!$media->canTransitionTo('QUEUED')) {
            return response()->json([
                'success' => false,
                'message' => "Cannot reprocess media in [{$media->status}] state.",
            ], 422);
        }

        $media->transitionTo('QUEUED', 'QUEUED');
        \App\Jobs\ProcessMediaAssetJob::dispatch($media->id);

        return response()->json([
            'success' => true,
            'data' => $media->fresh(['derivatives']),
        ]);
    }

    /**
     * Delete Media Asset with Workspace Security & Physical Storage Cleanup
     */
    public function destroy(Request $request, string $id, MediaStorageInterface $storageService)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $media = MediaAsset::where('id', $id)->first();

        if (!$media) {
            return response()->json([
                'success' => false,
                'message' => 'Media asset not found.',
            ], 404);
        }

        if ($workspaceId && $media->workspace_id !== $workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Access to media from another workspace denied.',
            ], 403);
        }

        // Physical deletion from S3/disk
        $storageService->deleteObject($media->storage_path);

        $media->delete();

        AuditEvent::create([
            'workspace_id' => $media->workspace_id,
            'action' => 'MEDIA_DELETED',
            'resource_type' => 'MediaAsset',
            'resource_id' => $id,
            'metadata' => ['original_name' => $media->original_name],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Media asset deleted successfully.',
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'workspaceId' => 'required|string',
            'file' => 'nullable|file|mimes:mp4,mov,webm,mkv|max:204800',
            'mockTitle' => 'nullable|string',
        ]);

        $workspaceId = Workspace::resolveId($request->input('workspaceId')) ?? $request->input('workspaceId');

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $filename = Str::random(24) . '.' . $file->getClientOriginalExtension();
            $disk = config('filesystems.default', 'public');
            $path = $file->storeAs('videos', $filename, $disk);

            $asset = MediaAsset::create([
                'workspace_id' => $workspaceId,
                'filename' => $filename,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'storage_path' => $path,
                'thumbnail_path' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
                'duration_seconds' => 45,
                'width' => 1080,
                'height' => 1920,
                'aspect_ratio' => '9:16',
                'codec' => 'H.264 / AAC',
                'status' => 'READY',
            ]);
        } else {
            $title = $request->input('mockTitle') ?? 'Studio Sample Video.mp4';
            $asset = MediaAsset::create([
                'workspace_id' => $workspaceId,
                'filename' => 'sample_' . Str::random(12) . '.mp4',
                'original_name' => $title,
                'mime_type' => 'video/mp4',
                'size_bytes' => 42100000,
                'storage_path' => 'videos/sample.mp4',
                'thumbnail_path' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
                'duration_seconds' => 58,
                'width' => 1080,
                'height' => 1920,
                'aspect_ratio' => '9:16',
                'codec' => 'H.264 / AAC',
                'status' => 'READY',
            ]);
        }

        AuditEvent::create([
            'workspace_id' => $workspaceId,
            'action' => 'MEDIA_UPLOADED',
            'resource_type' => 'MediaAsset',
            'resource_id' => $asset->id,
            'metadata' => ['filename' => $asset->original_name],
        ]);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = MediaAsset::with('derivatives');
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $asset = $query->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }
}
