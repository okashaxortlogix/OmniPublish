<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    /**
     * Return safe release metadata for deployment verification and audit trails.
     * Strictly avoids exposing any environment secrets, database credentials, or internal paths.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'application' => config('app.name', 'OmniPublish'),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env', 'production'),
            'commit_sha' => env('COMMIT_SHA', 'local-development'),
            'build_timestamp' => env('BUILD_TIMESTAMP', null),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
