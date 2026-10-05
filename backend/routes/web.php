<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\VersionController;

Route::get('/', function () {
    return view('welcome');
});

// Root Health Endpoints (ALB / Ingress / K8s probes)
Route::get('/health', [HealthController::class, 'index']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/version', [VersionController::class, 'show']);


