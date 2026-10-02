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

        $jobsQuery = PublishJob::query();
        $accountsQuery = SocialAccount::query();
        $batchesQuery = PublishBatch::query();

        if ($workspaceId) {
            $jobsQuery->where('workspace_id', $workspaceId);
            $accountsQuery->where('workspace_id', $workspaceId);
            $batchesQuery->where('workspace_id', $workspaceId);
        }

        $totalJobs = $jobsQuery->count();
        $publishedJobs = (clone $jobsQuery)->where('status', 'PUBLISHED')->count();
        $failedJobs = (clone $jobsQuery)->whereIn('status', ['FAILED', 'AUTH_REQUIRED'])->count();
        $activeAccounts = (clone $accountsQuery)->where('status', 'ACTIVE')->count();

        $successRate = $totalJobs > 0 ? round(($publishedJobs / $totalJobs) * 100, 1) : 100.0;

        // Platform breakdown
        $platforms = ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'];
        $breakdown = [];
        foreach ($platforms as $p) {
            $pQuery = (clone $jobsQuery)->where('platform', $p);
            $pTotal = $pQuery->count();
            $pSuccess = (clone $pQuery)->where('status', 'PUBLISHED')->count();
            $breakdown[$p] = [
                'total' => $pTotal,
                'published' => $pSuccess,
                'successRate' => $pTotal > 0 ? round(($pSuccess / $pTotal) * 100, 1) : 100.0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'totalPosts' => $publishedJobs,
                'activeChannels' => $activeAccounts,
                'successRate' => $successRate,
                'failedCount' => $failedJobs,
                'platformBreakdown' => $breakdown,
            ]
        ]);
    }
}
