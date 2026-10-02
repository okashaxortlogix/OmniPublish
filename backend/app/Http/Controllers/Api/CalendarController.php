<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublishBatch;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = PublishBatch::with(['publishJobs.socialAccount', 'contentItem.mediaAsset']);
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $batches = $query->latest()->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }
}
