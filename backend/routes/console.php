<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\MediaAsset;
use App\Jobs\PublishDestinationJob;
use App\Services\TokenRefreshService;

// 1. Core Artisan Demo Command
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 2. Dispatch Scheduled Publishing Batches
Artisan::command('publishing:dispatch-scheduled', function () {
    $this->info('Scanning for scheduled publish batches ready for dispatch...');
    
    $batches = PublishBatch::where('status', 'QUEUED')
        ->whereNotNull('scheduled_for')
        ->where('scheduled_for', '<=', now())
        ->with('publishJobs')
        ->get();

    $dispatchedCount = 0;
    foreach ($batches as $batch) {
        $batch->update(['status' => 'RUNNING']);
        foreach ($batch->publishJobs as $job) {
            if ($job->status === 'QUEUED') {
                PublishDestinationJob::dispatch($job->id);
                $dispatchedCount++;
            }
        }
    }

    $this->info("Dispatched {$dispatchedCount} destination jobs across {$batches->count()} batches.");
    Log::info("Scheduled dispatch completed: {$dispatchedCount} jobs queued.");
})->purpose('Find and dispatch publish batches whose scheduled time has passed');

// 3. Reap Stalled Publishing Jobs (Crash / Interruption Recovery)
Artisan::command('publishing:reap-stalled', function () {
    $this->info('Scanning for stalled in-flight publishing jobs (>15 minutes inactive)...');
    
    $stalledJobs = PublishJob::whereIn('status', ['UPLOADING', 'PROCESSING'])
        ->where('last_attempt_at', '<=', now()->subMinutes(15))
        ->get();

    $reapedCount = 0;
    foreach ($stalledJobs as $job) {
        if ($job->attempts_count < $job->max_attempts) {
            $job->update([
                'status' => 'RETRYING',
                'internal_error_details' => 'Worker stalled or timed out unexpectedly; auto-reaped for retry.',
            ]);
            PublishDestinationJob::dispatch($job->id)->delay(now()->addSeconds(10));
        } else {
            $job->update([
                'status' => 'FAILED',
                'failure_category' => 'WORKER_TIMEOUT',
                'user_facing_error_message' => 'Publishing operation stalled and exceeded maximum retry limits.',
                'internal_error_details' => 'Worker stalled; exceeded max_attempts.',
            ]);
        }
        $reapedCount++;
    }

    $this->info("Reaped and recovered {$reapedCount} stalled jobs.");
})->purpose('Detect and recover publishing jobs stranded in UPLOADING/PROCESSING');

// 4. Reconcile Processing and Due-Retry Publishing Jobs
Artisan::command('publishing:reconcile', function (\App\Services\PublishingEngine $engine) {
    $this->info('Starting publishing reconciliation pass...');

    $processingJobs = PublishJob::where('status', PublishJob::STATUS_PROCESSING)
        ->whereNotNull('remote_post_id')
        ->get();

    $reconciledProcessing = 0;
    foreach ($processingJobs as $job) {
        $engine->reconcileJob($job);
        $reconciledProcessing++;
    }

    $dueRetryJobs = PublishJob::where('status', PublishJob::STATUS_RETRYING)
        ->where('retry_at', '<=', now())
        ->get();

    $reconciledRetries = 0;
    foreach ($dueRetryJobs as $job) {
        $engine->reconcileJob($job);
        $reconciledRetries++;
    }

    $total = $reconciledProcessing + $reconciledRetries;
    $this->info("Reconciliation pass complete. Inspected {$reconciledProcessing} processing jobs and {$reconciledRetries} due retries ({$total} total).");
})->purpose('Reconcile jobs stuck in PROCESSING or due for retry');

// 5. Proactive OAuth Token Refresh
Artisan::command('tokens:refresh-expiring', function (TokenRefreshService $tokenService) {
    $this->info('Checking for social account access tokens expiring within 24 hours...');
    
    $expiringAccounts = SocialAccount::whereNotNull('token_expires_at')
        ->where('token_expires_at', '<=', now()->addHours(24))
        ->where('status', 'ACTIVE')
        ->get();

    $refreshed = 0;
    foreach ($expiringAccounts as $account) {
        try {
            $tokenService->refreshIfExpired($account);
            $refreshed++;
        } catch (\Throwable $e) {
            Log::error("Failed to refresh token for account {$account->id} ({$account->platform}): {$e->getMessage()}");
        }
    }

    $this->info("Refreshed {$refreshed} out of {$expiringAccounts->count()} expiring tokens.");
})->purpose('Proactively refresh expiring OAuth access tokens across all platforms');

// 6. Cleanup Stale Upload Authorizations
Artisan::command('media:cleanup-stale-uploads', function () {
    $this->info('Cleaning up abandoned upload authorizations older than 2 hours...');

    $staleUploads = MediaAsset::where('status', 'UPLOAD_AUTHORIZED')
        ->where('created_at', '<=', now()->subHours(2))
        ->get();

    $cleanedCount = 0;
    foreach ($staleUploads as $media) {
        $media->update([
            'status' => 'FAILED',
            'error_message' => 'Upload timed out: authorization expired without client confirmation.',
        ]);
        $cleanedCount++;
    }

    $this->info("Cleaned up {$cleanedCount} stale upload authorizations.");
})->purpose('Mark abandoned upload authorizations as failed');

// =========================================================================
// Centralized Scheduler Registrations
// =========================================================================
Schedule::command('publishing:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('publishing:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('publishing:reap-stalled')->everyTenMinutes()->withoutOverlapping();
Schedule::command('tokens:refresh-expiring')->hourly()->withoutOverlapping();
Schedule::command('media:cleanup-stale-uploads')->hourly()->withoutOverlapping();
