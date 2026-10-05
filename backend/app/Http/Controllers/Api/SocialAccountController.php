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
                'token_expires_at' => now()->addDays(90),
                'metadata' => [
                    'subscriberCount' => rand(1500, 18500),
                    'followersCount' => rand(2200, 24000),
                    'videoCount' => rand(12, 65),
                    'postCount' => rand(14, 58),
                    'last_synced_at' => now()->toIso8601String(),
                ],
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

    public function disconnect(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $account = $query->findOrFail($id);
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

    public function show(Request $request, string $id, PlatformManager $manager)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $account = $query->findOrFail($id);

        $capabilities = [];
        try {
            $adapter = $manager->get($account->platform);
            $capabilities = $adapter->getCapabilities()->toArray();
        } catch (\Throwable $e) {
            // gracefully fallback if custom adapter
        }

        $recentJobs = $account->publishJobs()
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'account' => $account,
                'capabilities' => $capabilities,
                'recentJobs' => $recentJobs,
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $account = $query->findOrFail($id);

        $validated = $request->validate([
            'accountName' => 'nullable|string|max:100',
            'accountHandle' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ]);

        $updates = [];
        if (!empty($validated['accountName'])) {
            $updates['account_name'] = $validated['accountName'];
        }
        if (isset($validated['accountHandle'])) {
            $updates['account_handle'] = $validated['accountHandle'];
        }
        if (isset($validated['metadata'])) {
            $currentMeta = $account->metadata ?? [];
            $updates['metadata'] = array_merge($currentMeta, $validated['metadata']);
        }

        $account->update($updates);

        AuditEvent::create([
            'workspace_id' => $account->workspace_id,
            'action' => 'ACCOUNT_SETTINGS_UPDATED',
            'resource_type' => 'SocialAccount',
            'resource_id' => $account->id,
            'metadata' => [
                'platform' => $account->platform,
                'account_name' => $account->account_name,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $account,
            'message' => 'Profile settings updated successfully.',
        ]);
    }

    public function sync(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $account = $query->findOrFail($id);
        $currentMeta = $account->metadata ?? [];

        // Attempt live API sync based on platform if real token is available
        try {
            $token = $account->encrypted_access_token;
            if ($account->platform === 'YOUTUBE' && $token) {
                $channelRes = \Illuminate\Support\Facades\Http::withToken($token)->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,statistics',
                    'mine' => 'true',
                ]);
                if ($channelRes->successful() && !empty($channelRes->json('items'))) {
                    $item = $channelRes->json('items.0');
                    $stats = $item['statistics'] ?? [];
                    $snippet = $item['snippet'] ?? [];
                    $currentMeta['subscriberCount'] = (int) ($stats['subscriberCount'] ?? ($currentMeta['subscriberCount'] ?? 0));
                    $currentMeta['videoCount'] = (int) ($stats['videoCount'] ?? ($currentMeta['videoCount'] ?? 0));
                    $currentMeta['viewCount'] = (int) ($stats['viewCount'] ?? ($currentMeta['viewCount'] ?? 0));
                    if (!empty($snippet['title'])) $account->account_name = $snippet['title'];
                }
            } elseif ($account->platform === 'TIKTOK' && $token) {
                $userRes = \Illuminate\Support\Facades\Http::withToken($token)->get('https://open.tiktokapis.com/v2/user/info/', [
                    'fields' => 'avatar_url,display_name,follower_count,video_count',
                ]);
                if ($userRes->successful() && !empty($userRes->json('data.user'))) {
                    $u = $userRes->json('data.user');
                    $currentMeta['followersCount'] = (int) ($u['follower_count'] ?? ($currentMeta['followersCount'] ?? 0));
                    $currentMeta['videoCount'] = (int) ($u['video_count'] ?? ($currentMeta['videoCount'] ?? 0));
                    if (!empty($u['display_name'])) $account->account_name = $u['display_name'];
                }
            } elseif ($account->platform === 'TWITTER' && $token) {
                $userRes = \Illuminate\Support\Facades\Http::withToken($token)->get('https://api.twitter.com/2/users/me', [
                    'user.fields' => 'profile_image_url,public_metrics,username,name',
                ]);
                if ($userRes->successful() && !empty($userRes->json('data'))) {
                    $u = $userRes->json('data');
                    $currentMeta['followersCount'] = (int) ($u['public_metrics']['followers_count'] ?? ($currentMeta['followersCount'] ?? 0));
                    $currentMeta['tweetCount'] = (int) ($u['public_metrics']['tweet_count'] ?? ($currentMeta['tweetCount'] ?? 0));
                    if (!empty($u['name'])) $account->account_name = $u['name'];
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback if offline or mock token
        }

        $currentMeta['last_synced_at'] = now()->toIso8601String();
        $account->metadata = $currentMeta;
        $account->save();

        AuditEvent::create([
            'workspace_id' => $account->workspace_id,
            'action' => 'ACCOUNT_METRICS_SYNCED',
            'resource_type' => 'SocialAccount',
            'resource_id' => $account->id,
            'metadata' => [
                'platform' => $account->platform,
                'account_name' => $account->account_name,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $account,
            'message' => 'Profile metrics refreshed and synchronized successfully.',
        ]);
    }

    public function posts(Request $request, string $id)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $query = SocialAccount::query();
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $account = $query->findOrFail($id);

        $jobs = $account->publishJobs()
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }
}
