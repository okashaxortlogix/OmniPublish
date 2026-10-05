<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use App\Models\User;
use App\Models\AuditEvent;
use App\Models\PublishJob;
use Illuminate\Support\Facades\Hash;

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
        $resolvedId = Workspace::resolveId($workspaceId) ?? $workspaceId;

        $members = WorkspaceMember::with('user')
            ->where('workspace_id', $resolvedId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    public function inviteMember(Request $request, string $workspaceId)
    {
        $resolvedId = Workspace::resolveId($workspaceId) ?? $workspaceId;
        $workspace = Workspace::findOrFail($resolvedId);

        $validated = $request->validate([
            'email' => 'required|email',
            'role' => 'required|string',
            'name' => 'nullable|string|max:100',
        ]);

        $role = strtoupper($validated['role']);
        if (!in_array($role, ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'])) {
            $role = 'EDITOR';
        }

        $email = strtolower($validated['email']);
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $validated['name'] ?? explode('@', $email)[0],
                'password' => Hash::make(Str::random(16)),
            ]
        );

        $member = WorkspaceMember::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['role' => $role]
        );

        AuditEvent::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'MEMBER_INVITED',
            'resource_type' => 'WorkspaceMember',
            'resource_id' => (string) $member->id,
            'metadata' => ['email' => $email, 'role' => $role],
        ]);

        return response()->json([
            'success' => true,
            'data' => $member->load('user'),
            'message' => 'Team member invited successfully.',
        ], 201);
    }

    public function removeMember(Request $request, string $workspaceId, string $memberId)
    {
        $resolvedId = Workspace::resolveId($workspaceId) ?? $workspaceId;

        $member = WorkspaceMember::where('workspace_id', $resolvedId)
            ->where('id', $memberId)
            ->first();

        if ($member) {
            $member->delete();

            AuditEvent::create([
                'workspace_id' => $resolvedId,
                'action' => 'MEMBER_REMOVED',
                'resource_type' => 'WorkspaceMember',
                'resource_id' => $memberId,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully.',
        ]);
    }

    public function auditLogs(Request $request, string $workspaceId)
    {
        $resolvedId = Workspace::resolveId($workspaceId) ?? $workspaceId;

        $logs = AuditEvent::with('user')
            ->where('workspace_id', $resolvedId)
            ->latest()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    public function notifications(Request $request)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $resolvedId = Workspace::resolveId($rawWorkspaceId) ?? $rawWorkspaceId;

        $notifications = [];

        // 1. Recent Audit Events
        $eventsQuery = AuditEvent::with('user')->latest()->limit(15);
        if ($resolvedId) {
            $eventsQuery->where('workspace_id', $resolvedId);
        }
        $events = $eventsQuery->get();

        foreach ($events as $event) {
            $notifications[] = [
                'id' => 'evt_' . $event->id,
                'category' => match ($event->action) {
                    'PUBLISH_BATCH_DISPATCHED', 'PUBLISH_BATCH_CREATED' => 'Publishing',
                    'SOCIAL_ACCOUNT_CONNECTED', 'SOCIAL_ACCOUNT_DISCONNECTED' => 'Account',
                    'MEMBER_INVITED', 'MEMBER_REMOVED' => 'Team',
                    default => 'Security',
                },
                'title' => str_replace('_', ' ', ucwords(strtolower($event->action), '_')),
                'description' => "Event on {$event->resource_type} " . ($event->metadata ? json_encode($event->metadata) : ''),
                'timestamp' => $event->created_at->diffForHumans(),
                'read' => false,
                'link' => match ($event->action) {
                    'PUBLISH_BATCH_DISPATCHED' => '/publishing',
                    'SOCIAL_ACCOUNT_CONNECTED' => '/connections',
                    'MEMBER_INVITED' => '/team',
                    default => '/settings/audit-logs',
                },
            ];
        }

        // 2. Failed / Completed Publish Jobs
        $jobsQuery = PublishJob::with('socialAccount')->latest()->limit(10);
        if ($resolvedId) {
            $jobsQuery->where('workspace_id', $resolvedId);
        }
        $jobs = $jobsQuery->get();

        foreach ($jobs as $job) {
            if ($job->status === 'FAILED') {
                $notifications[] = [
                    'id' => 'job_fail_' . $job->id,
                    'category' => 'Publishing',
                    'title' => "Publishing failed on {$job->platform}",
                    'description' => $job->user_facing_error_message ?: "Error dispatching video to {$job->platform}.",
                    'timestamp' => $job->updated_at->diffForHumans(),
                    'read' => false,
                    'link' => '/publishing',
                ];
            } elseif ($job->status === 'PUBLISHED') {
                $notifications[] = [
                    'id' => 'job_ok_' . $job->id,
                    'category' => 'Publishing',
                    'title' => "Published successfully to {$job->platform}",
                    'description' => "Video is now live on {$job->platform}.",
                    'timestamp' => $job->updated_at->diffForHumans(),
                    'read' => true,
                    'link' => $job->remote_post_url ?: '/publishing',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }
}
