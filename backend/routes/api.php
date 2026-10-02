<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PublishingController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OAuthController;

// 1. Health Checks
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

// 2. Authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// 3. Workspaces
Route::prefix('workspaces')->group(function () {
    Route::get('/', [WorkspaceController::class, 'index']);
    Route::post('/', [WorkspaceController::class, 'store']);
    Route::get('/{workspaceId}/members', [WorkspaceController::class, 'members']);
});

// 4. Social Accounts & Platforms
Route::prefix('social-accounts')->group(function () {
    Route::get('/', [SocialAccountController::class, 'index']);
    Route::post('/connect/{platform}', [SocialAccountController::class, 'connect']);
    Route::post('/{id}/disconnect', [SocialAccountController::class, 'disconnect']);
});

// 4.1 Real OAuth Handshakes
Route::prefix('oauth')->group(function () {
    Route::get('/youtube/authorize', [OAuthController::class, 'youtubeAuthorize']);
    Route::get('/google/callback', [OAuthController::class, 'googleCallback']);
    Route::post('/complete/youtube', [OAuthController::class, 'completeYouTube']);
    Route::post('/complete/instagram', [OAuthController::class, 'completeInstagram']);
    Route::get('/tiktok/authorize', [OAuthController::class, 'tiktokAuthorize']);
    Route::get('/tiktok/callback', [OAuthController::class, 'tiktokCallback']);
});

// 5. Media Library
Route::prefix('media')->group(function () {
    Route::get('/', [MediaController::class, 'index']);
    Route::post('/upload', [MediaController::class, 'upload']);
    Route::get('/{id}', [MediaController::class, 'show']);
});

// 6. Publishing Engine
Route::prefix('publish')->group(function () {
    Route::get('/batches', [PublishingController::class, 'index']);
    Route::post('/batches', [PublishingController::class, 'store']);
    Route::get('/batches/{id}', [PublishingController::class, 'show']);
    Route::post('/jobs/{id}/retry', [PublishingController::class, 'retryJob']);
    Route::post('/validate', [PublishingController::class, 'validateContent']);
});

// 7. Calendar & Scheduling
Route::get('/calendar', [CalendarController::class, 'index']);

// 8. Analytics
Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);
