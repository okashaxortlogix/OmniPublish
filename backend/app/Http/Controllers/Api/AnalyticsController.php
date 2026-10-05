<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\PublishBatch;
use App\Models\Workspace;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function summary(Request $request)
    {
        $rawWorkspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        if (!$workspaceId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'totalPosts' => 0,
                    'activeChannels' => 0,
                    'successRate' => 100.0,
                    'failedCount' => 0,
                    'platformBreakdown' => [],
                ]
            ]);
        }

        $cacheKey = "analytics_summary_{$workspaceId}";
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 15, function () use ($workspaceId) {
            $stats = PublishJob::where('workspace_id', $workspaceId)
                ->selectRaw('platform, status, count(*) as count')
                ->groupBy('platform', 'status')
                ->get();

            $totalJobs = 0;
            $publishedJobs = 0;
            $failedJobs = 0;
            $platforms = ['YOUTUBE', 'TIKTOK', 'INSTAGRAM', 'FACEBOOK', 'LINKEDIN', 'TWITTER'];
            $breakdown = [];
            foreach ($platforms as $p) {
                $breakdown[$p] = ['total' => 0, 'published' => 0, 'successRate' => 100.0];
            }

            foreach ($stats as $row) {
                $count = (int) $row->count;
                $totalJobs += $count;
                $p = $row->platform;

                if (isset($breakdown[$p])) {
                    $breakdown[$p]['total'] += $count;
                }

                if ($row->status === 'PUBLISHED') {
                    $publishedJobs += $count;
                    if (isset($breakdown[$p])) {
                        $breakdown[$p]['published'] += $count;
                    }
                } elseif (in_array($row->status, ['FAILED', 'AUTH_REQUIRED'])) {
                    $failedJobs += $count;
                }
            }

            foreach ($platforms as $p) {
                $t = $breakdown[$p]['total'];
                $s = $breakdown[$p]['published'];
                $breakdown[$p]['successRate'] = $t > 0 ? round(($s / $t) * 100, 1) : 100.0;
            }

            $activeAccounts = SocialAccount::where('workspace_id', $workspaceId)
                ->where('status', 'ACTIVE')
                ->count();

            $successRate = $totalJobs > 0 ? round(($publishedJobs / $totalJobs) * 100, 1) : 100.0;

            return [
                'totalPosts' => $publishedJobs,
                'activeChannels' => $activeAccounts,
                'successRate' => $successRate,
                'failedCount' => $failedJobs,
                'platformBreakdown' => $breakdown,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
