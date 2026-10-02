<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function upload(Request $request)
    {
        $request->validate([
            'workspaceId' => 'required|string',
            'file' => 'nullable|file|mimes:mp4,mov,webm,mkv|max:204800', // 200MB max for direct web upload
            'mockTitle' => 'nullable|string',
        ]);

        $workspaceId = Workspace::resolveId($request->input('workspaceId')) ?? $request->input('workspaceId');

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $filename = Str::random(24) . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('videos', $filename, 'public');

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
            // Mock asset creation for testing / seed
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

    public function show(string $id)
    {
        $asset = MediaAsset::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }
}
