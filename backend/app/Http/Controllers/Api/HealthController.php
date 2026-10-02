<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function live()
    {
        return response()->json([
            'status' => 'UP',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready()
    {
        $dbStatus = 'OK';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'FAILED: ' . $e->getMessage();
        }

        $allOk = $dbStatus === 'OK';

        return response()->json([
            'status' => $allOk ? 'READY' : 'DEGRADED',
            'database' => $dbStatus,
            'timestamp' => now()->toIso8601String(),
        ], $allOk ? 200 : 503);
    }
}
