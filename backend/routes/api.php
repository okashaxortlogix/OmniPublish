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
use App\Http\Controllers\Api\VersionController;

// 1. Health Checks & Release Identity
Route::get('/health', [HealthController::class, 'index']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/version', [VersionController::class, 'show']);



// 2. Authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'updatePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// 3. Workspaces
Route::prefix('workspaces')->group(function () {
    Route::get('/', [WorkspaceController::class, 'index']);
    Route::post('/', [WorkspaceController::class, 'store']);
    Route::get('/{workspaceId}/members', [WorkspaceController::class, 'members']);
    Route::post('/{workspaceId}/members', [WorkspaceController::class, 'inviteMember']);
    Route::delete('/{workspaceId}/members/{memberId}', [WorkspaceController::class, 'removeMember']);
    Route::get('/{workspaceId}/audit-logs', [WorkspaceController::class, 'auditLogs']);
});

// 4. Social Accounts & Platforms
Route::prefix('social-accounts')->group(function () {
    Route::get('/', [SocialAccountController::class, 'index']);
    Route::post('/connect/{platform}', [SocialAccountController::class, 'connect']);
    Route::get('/{id}', [SocialAccountController::class, 'show']);
    Route::put('/{id}', [SocialAccountController::class, 'update']);
    Route::post('/{id}/sync', [SocialAccountController::class, 'sync']);
    Route::get('/{id}/posts', [SocialAccountController::class, 'posts']);
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

    // Facebook
    Route::get('/facebook/authorize', [OAuthController::class, 'facebookAuthorize']);
    Route::get('/facebook/callback', [OAuthController::class, 'facebookCallback']);
    Route::post('/complete/facebook', [OAuthController::class, 'completeFacebook']);

    // LinkedIn
    Route::get('/linkedin/authorize', [OAuthController::class, 'linkedinAuthorize']);
    Route::get('/linkedin/callback', [OAuthController::class, 'linkedinCallback']);
    Route::post('/complete/linkedin', [OAuthController::class, 'completeLinkedIn']);

    // Twitter / X
    Route::get('/twitter/authorize', [OAuthController::class, 'twitterAuthorize']);
    Route::get('/twitter/callback', [OAuthController::class, 'twitterCallback']);
    Route::post('/complete/twitter', [OAuthController::class, 'completeTwitter']);

    // Pinterest
    Route::get('/pinterest/authorize', [OAuthController::class, 'pinterestAuthorize']);
    Route::get('/pinterest/callback', [OAuthController::class, 'pinterestCallback']);
    Route::post('/complete/pinterest', [OAuthController::class, 'completePinterest']);
});

// 5. Media Library
Route::prefix('media')->group(function () {
    Route::get('/', [MediaController::class, 'index']);
    Route::post('/upload', [MediaController::class, 'upload']);
    Route::post('/upload-authorization', [MediaController::class, 'authorizeUpload']);
    Route::post('/confirm-upload', [MediaController::class, 'confirmUpload']);
    Route::post('/{id}/direct-content', [MediaController::class, 'directContent']);
    Route::get('/{id}', [MediaController::class, 'show']);
    Route::post('/{id}/reprocess', [MediaController::class, 'reprocess']);
    Route::delete('/{id}', [MediaController::class, 'destroy']);
});

// 6. Publishing Engine
Route::prefix('publish')->group(function () {
    Route::get('/batches', [PublishingController::class, 'index']);
    Route::post('/batches', [PublishingController::class, 'store']);
    Route::get('/batches/{id}', [PublishingController::class, 'show']);
    Route::get('/jobs/{id}', [PublishingController::class, 'showJob']);
    Route::post('/jobs/{id}/retry', [PublishingController::class, 'retryJob']);
    Route::post('/jobs/{id}/cancel', [PublishingController::class, 'cancelJob']);
    Route::post('/jobs/{id}/reconcile', [PublishingController::class, 'reconcileJob']);
    Route::delete('/batches/{id}', [PublishingController::class, 'destroy']);
    Route::post('/validate', [PublishingController::class, 'validateContent']);
});

// 7. Calendar & Scheduling
Route::get('/calendar', [CalendarController::class, 'index']);

// 8. Analytics
Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);

// 9. Notifications
Route::get('/notifications', [WorkspaceController::class, 'notifications']);
