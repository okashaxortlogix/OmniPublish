<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * Root health endpoint returning readiness state.
     */
    public function index()
    {
        return $this->ready();
    }

    /**
     * Liveness probe: verifies application process is running.
     * Does NOT depend on external providers.
     */
    public function live()
    {
        return response()->json([
            'status' => 'UP',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness probe: verifies required dependencies (PostgreSQL, Redis, Object Storage).
     * Strictly avoids leaking credentials, internal hostnames, or raw exception traces.
     */
    public function ready()
    {
        $dbStatus = 'OK';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            Log::error('Readiness probe Database failure', ['message' => $e->getMessage()]);
            $dbStatus = 'UNHEALTHY';
        }

        $redisStatus = 'SKIPPED';
        if (config('queue.default') === 'redis' || config('cache.default') === 'redis') {
            try {
                Redis::connection()->ping();
                $redisStatus = 'OK';
            } catch (\Throwable $e) {
                Log::error('Readiness probe Redis failure', ['message' => $e->getMessage()]);
                $redisStatus = 'UNHEALTHY';
            }
        }

        $storageStatus = 'OK';
        try {
            Storage::disk()->exists('.healthcheck');
        } catch (\Throwable $e) {
            Log::error('Readiness probe Storage failure', ['message' => $e->getMessage()]);
            $storageStatus = 'UNHEALTHY';
        }

        $allOk = ($dbStatus === 'OK') && ($storageStatus === 'OK') && in_array($redisStatus, ['OK', 'SKIPPED'], true);

        return response()->json([
            'status' => $allOk ? 'READY' : 'DEGRADED',
            'checks' => [
                'database' => $dbStatus,
                'redis' => $redisStatus,
                'storage' => $storageStatus,
            ],
            'timestamp' => now()->toIso8601String(),
        ], $allOk ? 200 : 503);
    }
}

