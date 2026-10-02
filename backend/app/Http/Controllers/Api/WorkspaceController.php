<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = Workspace::withCount(['members', 'socialAccounts', 'publishBatches'])->get();

        return response()->json([
            'success' => true,
            'data' => $workspaces,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:50',
            'userId' => 'nullable|integer',
        ]);

        $userId = $validated['userId'] ?? 1;

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']) . '-' . substr(md5(uniqid()), 0, 6),
            'owner_id' => $userId,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'role' => 'OWNER',
        ]);

        return response()->json([
            'success' => true,
            'data' => $workspace,
        ], 201);
    }

    public function members(string $workspaceId)
    {
        $members = WorkspaceMember::with('user')
            ->where('workspace_id', $workspaceId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }
}
