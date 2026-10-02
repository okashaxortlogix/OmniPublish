<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\AuditEvent;
use App\Platforms\Services\PlatformManager;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function index(Request $request, PlatformManager $manager)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $accounts = $query->get();
        $capabilities = $manager->getAllCapabilities();

        return response()->json([
            'success' => true,
            'data' => [
                'accounts' => $accounts,
                'capabilities' => $capabilities,
            ]
        ]);
    }

    public function connect(Request $request, string $platform)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accountName' => 'required|string',
            'accountHandle' => 'nullable|string',
            'avatarUrl' => 'nullable|string',
        ]);

        $platformKey = strtoupper($platform);

        // Upsert or create social account
        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => Workspace::resolveId($validated['workspaceId']),
                'platform' => $platformKey,
            ],
            [
                'platform_account_id' => 'mock_acc_' . substr(md5(uniqid()), 0, 8),
                'account_name' => $validated['accountName'],
                'account_handle' => $validated['accountHandle'] ?? ('@' . strtolower(str_replace(' ', '', $validated['accountName']))),
                'avatar_url' => $validated['avatarUrl'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
                'status' => 'ACTIVE',
                'scopes' => ['video.publish', 'user.info.basic'],
                'encrypted_access_token' => 'mock_token_' . bin2hex(random_bytes(16)),
                'token_expires_at' => now()->addDays(60),
            ]
        );

        AuditEvent::create([
            'workspace_id' => $account->workspace_id,
            'action' => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id' => $account->id,
            'metadata' => ['platform' => $platformKey],
        ]);

        return response()->json([
            'success' => true,
            'data' => $account,
        ]);
    }

    public function disconnect(string $id)
    {
        $account = SocialAccount::findOrFail($id);
        $account->update(['status' => 'REVOKED']);

        AuditEvent::create([
            'workspace_id' => $account->workspace_id,
            'action' => 'ACCOUNT_DISCONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id' => $account->id,
            'metadata' => ['platform' => $account->platform],
        ]);

        return response()->json([
            'success' => true,
            'message' => "Account {$account->account_name} disconnected successfully."
        ]);
    }
}
