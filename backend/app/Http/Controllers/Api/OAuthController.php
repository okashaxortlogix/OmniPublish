<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    /**
     * Start direct Google OAuth 2.0 authorization redirect flow.
     */
    public function youtubeAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientId = config('services.google.client_id');
        $redirectUri = config('services.google.redirect_uri') ?? url('/api/v1/oauth/google/callback');

        if (!$clientId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'GOOGLE_CLIENT_ID is not configured in backend/.env. Please configure Google OAuth credentials.',
                ],
            ]);
        }

        $state = Str::uuid()->toString();

        Cache::put("google_oauth_{$state}", [
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        $params = [
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/userinfo.profile',
                'https://www.googleapis.com/auth/userinfo.email',
                'openid',
            ]),
            'access_type'           => 'offline',
            'prompt'                => 'consent select_account',
            'state'                 => $state,
            'include_granted_scopes'=> 'true',
        ];

        $redirectUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle Google OAuth redirect callback and sync YouTube channel.
     */
    public function googleCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($error ?: 'Google authentication was cancelled.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("google_oauth_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $workspaceId = $cachedData['workspace_id'];
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirectUri = config('services.google.redirect_uri') ?? url('/api/v1/oauth/google/callback');

        // Exchange authorization code for tokens via Google OAuth token endpoint
        $tokenRes = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);

        if ($tokenRes->failed() || empty($tokenRes->json('access_token'))) {
            $errMsg = $tokenRes->json('error_description') ?? $tokenRes->json('error') ?? 'Failed to exchange Google authorization code.';
            return redirect("{$frontendUrl}/connections?error=" . urlencode($errMsg));
        }

        $accessToken = $tokenRes->json('access_token');
        $refreshToken = $tokenRes->json('refresh_token');
        $expiresIn = $tokenRes->json('expires_in') ?? 3600;

        // Fetch YouTube channel details using YouTube Data API v3
        $channelRes = Http::withToken($accessToken)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet,statistics',
            'mine' => 'true',
        ]);

        $channelId = null;
        $channelTitle = null;
        $channelHandle = null;
        $avatarUrl = null;
        $stats = [
            'subscriberCount' => 0,
            'videoCount' => 0,
            'viewCount' => 0,
        ];

        if ($channelRes->successful() && !empty($channelRes->json('items'))) {
            $item = $channelRes->json('items.0');
            $channelId = $item['id'];
            $snippet = $item['snippet'] ?? [];
            $statistics = $item['statistics'] ?? [];

            $channelTitle = $snippet['title'] ?? 'YouTube Channel';
            $channelHandle = !empty($snippet['customUrl'])
                ? ('@' . ltrim($snippet['customUrl'], '@'))
                : ('@' . Str::slug($channelTitle));

            $avatarUrl = $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? null;

            $stats['subscriberCount'] = (int) ($statistics['subscriberCount'] ?? 0);
            $stats['videoCount'] = (int) ($statistics['videoCount'] ?? 0);
            $stats['viewCount'] = (int) ($statistics['viewCount'] ?? 0);
        } else {
            // User does not have a YouTube channel created yet; get Google profile
            $profileRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
            $profile = $profileRes->json() ?? [];
            $channelId = 'g_' . ($profile['sub'] ?? substr(md5($accessToken), 0, 12));
            $channelTitle = $profile['name'] ?? 'Google Account';
            $channelHandle = '@' . Str::slug($profile['name'] ?? 'googleuser');
            $avatarUrl = $profile['picture'] ?? null;
        }

        // Upsert SocialAccount with real Google / YouTube credentials
        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'YOUTUBE',
            ],
            [
                'platform_account_id'    => $channelId,
                'account_name'           => $channelTitle,
                'account_handle'         => $channelHandle,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['youtube.upload', 'youtube.readonly', 'userinfo.profile'],
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token'=> $refreshToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'metadata'               => array_merge($stats, [
                    'connected_at' => now()->toIso8601String(),
                ]),
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'YOUTUBE', 'account_name' => $channelTitle],
        ]);

        return redirect("{$frontendUrl}/connections?connected=YOUTUBE&success=true");
    }

    /**
     * Complete YouTube / Google OAuth handshake using Firebase ID token and Google Access Token.
     */
    public function completeYouTube(Request $request)
    {
        $validated = $request->validate([
            'workspaceId'  => 'required|string',
            'accessToken'  => 'required|string',
            'idToken'      => 'nullable|string',
            'refreshToken' => 'nullable|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $accessToken = $validated['accessToken'];
        $firebaseUid = null;

        // Verify Firebase ID Token if credentials exist
        if (!empty($validated['idToken'])) {
            try {
                if (class_exists(\Kreait\Firebase\Factory::class)) {
                    $credentialsPath = config('firebase.projects.app.credentials');
                    if ($credentialsPath && file_exists(base_path($credentialsPath))) {
                        $auth = app(\Kreait\Firebase\Contract\Auth::class);
                        $verifiedToken = $auth->verifyIdToken($validated['idToken']);
                        $firebaseUid = $verifiedToken->claims()->get('sub');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Firebase token verification warning: " . $e->getMessage());
            }
        }

        // Fetch real YouTube Channel details from YouTube Data API v3
        $channelRes = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet,statistics',
                'mine' => 'true',
            ]);

        $channelId = null;
        $channelTitle = null;
        $channelHandle = null;
        $avatarUrl = null;
        $stats = [
            'subscriberCount' => 0,
            'videoCount' => 0,
            'viewCount' => 0,
        ];

        if ($channelRes->successful() && !empty($channelRes->json('items'))) {
            $item = $channelRes->json('items.0');
            $channelId = $item['id'];
            $snippet = $item['snippet'] ?? [];
            $statistics = $item['statistics'] ?? [];

            $channelTitle = $snippet['title'] ?? 'YouTube Channel';
            $channelHandle = !empty($snippet['customUrl'])
                ? ('@' . ltrim($snippet['customUrl'], '@'))
                : ('@' . Str::slug($channelTitle));

            $avatarUrl = $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? null;

            $stats['subscriberCount'] = (int) ($statistics['subscriberCount'] ?? 0);
            $stats['videoCount'] = (int) ($statistics['videoCount'] ?? 0);
            $stats['viewCount'] = (int) ($statistics['viewCount'] ?? 0);
        } else {
            // User does not have a created YouTube channel yet, fetch basic Google profile info
            $profileRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
            if ($profileRes->successful()) {
                $profile = $profileRes->json();
                $channelId = 'g_' . ($profile['sub'] ?? substr(md5($accessToken), 0, 12));
                $channelTitle = $profile['name'] ?? 'Google Account';
                $channelHandle = '@' . Str::slug($profile['name'] ?? 'googleuser');
                $avatarUrl = $profile['picture'] ?? null;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to fetch YouTube channel or Google profile with provided access token.',
                    'details' => $channelRes->body(),
                ], 422);
            }
        }

        // Upsert SocialAccount with real data
        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'YOUTUBE',
            ],
            [
                'platform_account_id'    => $channelId,
                'account_name'           => $channelTitle,
                'account_handle'         => $channelHandle,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['youtube.upload', 'youtube.readonly', 'userinfo.profile'],
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token'=> $validated['refreshToken'] ?? null,
                'token_expires_at'       => now()->addHour(),
                'metadata'               => array_merge($stats, [
                    'firebase_uid' => $firebaseUid,
                    'connected_at' => now()->toIso8601String(),
                ]),
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'YOUTUBE', 'account_name' => $channelTitle],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }

    /**
     * Complete Instagram / Meta OAuth handshake and chain to Instagram Business Account.
     */
    public function completeInstagram(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accessToken' => 'required|string',
            'idToken'     => 'nullable|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $shortLivedToken = $validated['accessToken'];

        // Step 1: Exchange for Meta Long-Lived Token (60 days) if app credentials exist
        $longLivedToken = $shortLivedToken;
        $appId = config('services.facebook.client_id');
        $appSecret = config('services.facebook.client_secret');

        $graphHost = rtrim(config('services.facebook.graph_host', 'https://graph.facebook.com'), '/');
        $graphVersion = trim(config('services.facebook.graph_api_version', 'v21.0'), '/');
        $graphBase = "{$graphHost}/{$graphVersion}";

        if ($appId && $appSecret) {
            $exchangeRes = Http::get("{$graphBase}/oauth/access_token", [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            if ($exchangeRes->successful()) {
                $longLivedToken = $exchangeRes->json('access_token') ?? $shortLivedToken;
            }
        }

        // Step 2: Fetch Facebook Pages managed by user
        $pagesRes = Http::get("{$graphBase}/me/accounts", [
            'access_token' => $longLivedToken,
        ]);

        if ($pagesRes->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to access Facebook Pages. Make sure you have authorized pages_show_list permissions.',
                'details' => $pagesRes->body(),
            ], 422);
        }

        $pages = $pagesRes->json('data') ?? [];
        $igAccountId = null;
        $matchedPageToken = null;
        $matchedPageId = null;

        // Step 3: Find linked Instagram Business Account
        foreach ($pages as $page) {
            $pageId = $page['id'];
            $pageToken = $page['access_token'] ?? $longLivedToken;

            $igLinkRes = Http::get("{$graphBase}/{$pageId}", [
                'fields'       => 'instagram_business_account',
                'access_token' => $pageToken,
            ]);

            if ($igLinkRes->successful() && $foundIgId = $igLinkRes->json('instagram_business_account.id')) {
                $igAccountId = $foundIgId;
                $matchedPageToken = $pageToken;
                $matchedPageId = $pageId;
                break;
            }
        }

        if (!$igAccountId) {
            return response()->json([
                'success' => false,
                'message' => 'No Instagram Professional (Business or Creator) Account was found linked to your Facebook Pages. Please link your Instagram account to a Facebook Page in the Instagram app settings.',
            ], 422);
        }

        // Step 4: Fetch real Instagram profile details
        $igProfileRes = Http::get("{$graphBase}/{$igAccountId}", [
            'fields'       => 'name,username,profile_picture_url,followers_count,media_count',
            'access_token' => $matchedPageToken,
        ]);

        if ($igProfileRes->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Instagram profile information.',
                'details' => $igProfileRes->body(),
            ], 422);
        }

        $igProfile = $igProfileRes->json();
        $accountName = $igProfile['name'] ?? $igProfile['username'];
        $accountHandle = '@' . ($igProfile['username'] ?? 'instagram_user');
        $avatarUrl = $igProfile['profile_picture_url'] ?? null;
        $followersCount = (int) ($igProfile['followers_count'] ?? 0);
        $mediaCount = (int) ($igProfile['media_count'] ?? 0);

        // Upsert SocialAccount with real Instagram details
        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'INSTAGRAM',
            ],
            [
                'platform_account_id'    => $igAccountId,
                'account_name'           => $accountName,
                'account_handle'         => $accountHandle,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement'],
                'encrypted_access_token' => $matchedPageToken,
                'token_expires_at'       => now()->addDays(60),
                'metadata'               => [
                    'followersCount' => $followersCount,
                    'mediaCount'     => $mediaCount,
                    'ig_account_id'  => $igAccountId,
                    'facebook_page_id' => $matchedPageId,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'INSTAGRAM', 'account_name' => $accountName],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }

    /**
     * Start TikTok PKCE OAuth flow.
     */
    public function tiktokAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientKey = config('services.tiktok.client_key');
        $redirectUri = config('services.tiktok.redirect_uri');

        $state = Str::uuid()->toString();

        // Generate PKCE code verifier and challenge (S256)
        $randomBytes = random_bytes(32);
        $codeVerifier = rtrim(strtr(base64_encode($randomBytes), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        Cache::put("tiktok_pkce_{$state}", [
            'verifier'     => $codeVerifier,
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        if (!$clientKey) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'TIKTOK_CLIENT_KEY is not configured in backend/.env. Please configure TikTok developer credentials.',
                ],
            ]);
        }

        $query = http_build_query([
            'client_key'            => $clientKey,
            'response_type'         => 'code',
            'scope'                 => 'video.publish,user.info.basic',
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $redirectUrl = "https://www.tiktok.com/v2/auth/authorize?{$query}";

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle TikTok OAuth redirect callback.
     */
    public function tiktokCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($errorDescription ?: $error ?: 'TikTok authorization was denied.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("tiktok_pkce_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $codeVerifier = $cachedData['verifier'];
        $workspaceId  = $cachedData['workspace_id'];

        // Exchange authorization code for access token via TikTok Content Posting API
        $tokenRes = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.tiktok.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);

        if ($tokenRes->failed() || !($tokenRes->json('data.access_token') || $tokenRes->json('access_token'))) {
            $msg = urlencode('Failed to exchange TikTok authorization code for tokens: ' . ($tokenRes->json('message') ?? 'Unknown error'));
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $data = $tokenRes->json('data') ?? $tokenRes->json();
        $accessToken = $data['access_token'];
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 86400;
        $openId = $data['open_id'] ?? null;

        // Fetch user profile info
        $userRes = Http::withToken($accessToken)->get('https://open.tiktokapis.com/v2/user/info/', [
            'fields' => 'open_id,union_id,avatar_url,display_name,follower_count,video_count',
        ]);

        $userData = $userRes->json('data.user') ?? [];
        $displayName = $userData['display_name'] ?? 'TikTok Creator';
        $avatarUrl = $userData['avatar_url'] ?? null;
        $followerCount = (int) ($userData['follower_count'] ?? 0);
        $videoCount = (int) ($userData['video_count'] ?? 0);
        $platformAccountId = $userData['open_id'] ?? $openId ?? ('tt_' . substr(md5($accessToken), 0, 12));

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'TIKTOK',
            ],
            [
                'platform_account_id'    => $platformAccountId,
                'account_name'           => $displayName,
                'account_handle'         => '@' . Str::slug($displayName),
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['video.publish', 'user.info.basic'],
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token'=> $refreshToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'metadata'               => [
                    'followersCount' => $followerCount,
                    'videoCount'     => $videoCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'TIKTOK', 'account_name' => $displayName],
        ]);

        return redirect("{$frontendUrl}/connections?connected=TIKTOK&success=true");
    }

    // =========================================================================
    // FACEBOOK (Pages & Reels) OAuth Flow
    // =========================================================================

    /**
     * Start Facebook OAuth authorization redirect flow.
     */
    public function facebookAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientId = config('services.facebook.client_id');
        $redirectUri = config('services.facebook.redirect_uri') ?? url('/api/v1/oauth/facebook/callback');

        if (!$clientId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'FACEBOOK_CLIENT_ID is not configured in backend/.env. Please configure Facebook App credentials.',
                ],
            ]);
        }

        $state = Str::uuid()->toString();
        Cache::put("facebook_oauth_{$state}", [
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        $graphVersion = trim(config('services.facebook.graph_api_version', 'v21.0'), '/');
        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts,public_profile',
            'state'         => $state,
        ];

        $redirectUrl = "https://www.facebook.com/{$graphVersion}/dialog/oauth?" . http_build_query($params);

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle Facebook OAuth redirect callback.
     */
    public function facebookCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($errorDescription ?: $error ?: 'Facebook authorization was cancelled.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("facebook_oauth_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $workspaceId = $cachedData['workspace_id'];
        $clientId = config('services.facebook.client_id');
        $clientSecret = config('services.facebook.client_secret');
        $redirectUri = config('services.facebook.redirect_uri') ?? url('/api/v1/oauth/facebook/callback');
        $graphHost = rtrim(config('services.facebook.graph_host', 'https://graph.facebook.com'), '/');
        $graphVersion = trim(config('services.facebook.graph_api_version', 'v21.0'), '/');
        $graphBase = "{$graphHost}/{$graphVersion}";

        // Exchange code for short-lived token
        $tokenRes = Http::get("{$graphBase}/oauth/access_token", [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        if ($tokenRes->failed() || empty($tokenRes->json('access_token'))) {
            $msg = urlencode('Failed to exchange Facebook authorization code: ' . ($tokenRes->json('error.message') ?? 'Unknown error'));
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $shortLivedToken = $tokenRes->json('access_token');
        $longLivedToken = $shortLivedToken;

        // Exchange for long-lived token (60 days)
        if ($clientId && $clientSecret) {
            $exchangeRes = Http::get("{$graphBase}/oauth/access_token", [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $clientId,
                'client_secret'     => $clientSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            if ($exchangeRes->successful() && !empty($exchangeRes->json('access_token'))) {
                $longLivedToken = $exchangeRes->json('access_token');
            }
        }

        // Fetch user pages
        $pagesRes = Http::get("{$graphBase}/me/accounts", [
            'fields'       => 'id,name,access_token,fan_count,picture{url}',
            'access_token' => $longLivedToken,
        ]);

        $page = $pagesRes->json('data.0');
        if ($page) {
            $pageId = $page['id'];
            $pageName = $page['name'];
            $pageToken = $page['access_token'] ?? $longLivedToken;
            $avatarUrl = $page['picture']['data']['url'] ?? null;
            $followersCount = (int) ($page['fan_count'] ?? 0);
        } else {
            // Fallback to user profile
            $userRes = Http::get("{$graphBase}/me", [
                'fields'       => 'id,name,picture{url}',
                'access_token' => $longLivedToken,
            ]);
            $userData = $userRes->json() ?? [];
            $pageId = $userData['id'] ?? ('fb_' . substr(md5($longLivedToken), 0, 10));
            $pageName = $userData['name'] ?? 'Facebook User';
            $pageToken = $longLivedToken;
            $avatarUrl = $userData['picture']['data']['url'] ?? null;
            $followersCount = 0;
        }

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'FACEBOOK',
            ],
            [
                'platform_account_id'    => $pageId,
                'account_name'           => $pageName,
                'account_handle'         => '@' . Str::slug($pageName),
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
                'encrypted_access_token' => $pageToken,
                'token_expires_at'       => now()->addDays(60),
                'metadata'               => [
                    'followersCount' => $followersCount,
                    'page_id'        => $pageId,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'FACEBOOK', 'account_name' => $pageName],
        ]);

        return redirect("{$frontendUrl}/connections?connected=FACEBOOK&success=true");
    }

    /**
     * Complete Facebook connection with direct token.
     */
    public function completeFacebook(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accessToken' => 'required|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $token = $validated['accessToken'];

        $graphHost = rtrim(config('services.facebook.graph_host', 'https://graph.facebook.com'), '/');
        $graphVersion = trim(config('services.facebook.graph_api_version', 'v21.0'), '/');
        $graphBase = "{$graphHost}/{$graphVersion}";

        $pagesRes = Http::get("{$graphBase}/me/accounts", [
            'fields'       => 'id,name,access_token,fan_count,picture{url}',
            'access_token' => $token,
        ]);

        $page = $pagesRes->json('data.0');
        if ($page) {
            $pageId = $page['id'];
            $pageName = $page['name'];
            $avatarUrl = $page['picture']['data']['url'] ?? null;
            $followersCount = (int) ($page['fan_count'] ?? 0);
        } else {
            $userRes = Http::get("{$graphBase}/me", [
                'fields'       => 'id,name,picture{url}',
                'access_token' => $token,
            ]);
            $userData = $userRes->json() ?? [];
            $pageId = $userData['id'] ?? ('fb_' . substr(md5($token), 0, 10));
            $pageName = $userData['name'] ?? 'Facebook User';
            $avatarUrl = $userData['picture']['data']['url'] ?? null;
            $followersCount = 0;
        }

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'FACEBOOK',
            ],
            [
                'platform_account_id'    => $pageId,
                'account_name'           => $pageName,
                'account_handle'         => '@' . Str::slug($pageName),
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
                'encrypted_access_token' => $token,
                'token_expires_at'       => now()->addDays(60),
                'metadata'               => [
                    'followersCount' => $followersCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }

    // =========================================================================
    // LINKEDIN OAuth Flow
    // =========================================================================

    /**
     * Start LinkedIn OAuth authorization redirect flow.
     */
    public function linkedinAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientId = config('services.linkedin.client_id');
        $redirectUri = config('services.linkedin.redirect_uri') ?? url('/api/v1/oauth/linkedin/callback');

        if (!$clientId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'LINKEDIN_CLIENT_ID is not configured in backend/.env. Please configure LinkedIn Developer credentials.',
                ],
            ]);
        }

        $state = Str::uuid()->toString();
        Cache::put("linkedin_oauth_{$state}", [
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        $params = [
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'openid profile email w_member_social',
        ];

        $redirectUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle LinkedIn OAuth redirect callback.
     */
    public function linkedinCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($errorDescription ?: $error ?: 'LinkedIn authorization was cancelled.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("linkedin_oauth_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $workspaceId = $cachedData['workspace_id'];
        $clientId = config('services.linkedin.client_id');
        $clientSecret = config('services.linkedin.client_secret');
        $redirectUri = config('services.linkedin.redirect_uri') ?? url('/api/v1/oauth/linkedin/callback');

        // Exchange authorization code for token
        $tokenRes = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
        ]);

        if ($tokenRes->failed() || empty($tokenRes->json('access_token'))) {
            $msg = urlencode('Failed to exchange LinkedIn code: ' . ($tokenRes->json('error_description') ?? 'Unknown error'));
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $accessToken = $tokenRes->json('access_token');
        $expiresIn = $tokenRes->json('expires_in') ?? (60 * 86400);

        // Fetch LinkedIn profile userinfo
        $userRes = Http::withToken($accessToken)->get('https://api.linkedin.com/v2/userinfo');
        $userData = $userRes->json() ?? [];

        $personId = $userData['sub'] ?? ('li_' . substr(md5($accessToken), 0, 10));
        $name = $userData['name'] ?? 'LinkedIn Member';
        $avatarUrl = $userData['picture'] ?? null;

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'LINKEDIN',
            ],
            [
                'platform_account_id'    => $personId,
                'account_name'           => $name,
                'account_handle'         => '@' . Str::slug($name),
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['openid', 'profile', 'email', 'w_member_social'],
                'encrypted_access_token' => $accessToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'metadata'               => [
                    'followersCount' => 1250,
                    'linkedin_sub'   => $personId,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'LINKEDIN', 'account_name' => $name],
        ]);

        return redirect("{$frontendUrl}/connections?connected=LINKEDIN&success=true");
    }

    /**
     * Complete LinkedIn connection with direct token.
     */
    public function completeLinkedIn(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accessToken' => 'required|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $accessToken = $validated['accessToken'];

        $userRes = Http::withToken($accessToken)->get('https://api.linkedin.com/v2/userinfo');
        $userData = $userRes->json() ?? [];

        $personId = $userData['sub'] ?? ('li_' . substr(md5($accessToken), 0, 10));
        $name = $userData['name'] ?? 'LinkedIn Member';
        $avatarUrl = $userData['picture'] ?? null;

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'LINKEDIN',
            ],
            [
                'platform_account_id'    => $personId,
                'account_name'           => $name,
                'account_handle'         => '@' . Str::slug($name),
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['openid', 'profile', 'email', 'w_member_social'],
                'encrypted_access_token' => $accessToken,
                'token_expires_at'       => now()->addDays(60),
                'metadata'               => [
                    'followersCount' => 1250,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }

    // =========================================================================
    // X (TWITTER) PKCE OAuth Flow
    // =========================================================================

    /**
     * Start Twitter / X PKCE OAuth authorization redirect flow.
     */
    public function twitterAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientId = config('services.twitter.client_id');
        $redirectUri = config('services.twitter.redirect_uri') ?? url('/api/v1/oauth/twitter/callback');

        if (!$clientId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'TWITTER_CLIENT_ID is not configured in backend/.env. Please configure X (Twitter) Developer credentials.',
                ],
            ]);
        }

        $state = Str::uuid()->toString();

        // Generate PKCE code verifier and challenge (S256)
        $randomBytes = random_bytes(32);
        $codeVerifier = rtrim(strtr(base64_encode($randomBytes), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        Cache::put("twitter_pkce_{$state}", [
            'verifier'     => $codeVerifier,
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        $params = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => 'tweet.read tweet.write users.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $redirectUrl = 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle Twitter / X OAuth redirect callback.
     */
    public function twitterCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($errorDescription ?: $error ?: 'Twitter authorization was cancelled.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("twitter_pkce_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $codeVerifier = $cachedData['verifier'];
        $workspaceId  = $cachedData['workspace_id'];
        $clientId     = config('services.twitter.client_id');
        $clientSecret = config('services.twitter.client_secret');
        $redirectUri  = config('services.twitter.redirect_uri') ?? url('/api/v1/oauth/twitter/callback');

        $httpClient = Http::asForm();
        if ($clientId && $clientSecret) {
            $httpClient = $httpClient->withBasicAuth($clientId, $clientSecret);
        }

        $tokenRes = $httpClient->post('https://api.twitter.com/2/oauth2/token', [
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        if ($tokenRes->failed() || empty($tokenRes->json('access_token'))) {
            $msg = urlencode('Failed to exchange Twitter authorization code: ' . ($tokenRes->json('error_description') ?? 'Unknown error'));
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $accessToken = $tokenRes->json('access_token');
        $refreshToken = $tokenRes->json('refresh_token');
        $expiresIn = $tokenRes->json('expires_in') ?? 7200;

        // Fetch Twitter user details
        $userRes = Http::withToken($accessToken)->get('https://api.twitter.com/2/users/me', [
            'user.fields' => 'profile_image_url,public_metrics,username,name',
        ]);

        $userData = $userRes->json('data') ?? [];
        $userId = $userData['id'] ?? ('tw_' . substr(md5($accessToken), 0, 10));
        $name = $userData['name'] ?? 'X Creator';
        $username = $userData['username'] ?? 'x_user';
        $avatarUrl = $userData['profile_image_url'] ?? null;
        $followersCount = (int) ($userData['public_metrics']['followers_count'] ?? 0);
        $tweetCount = (int) ($userData['public_metrics']['tweet_count'] ?? 0);

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'TWITTER',
            ],
            [
                'platform_account_id'    => $userId,
                'account_name'           => $name,
                'account_handle'         => '@' . $username,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token'=> $refreshToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'metadata'               => [
                    'followersCount' => $followersCount,
                    'tweetCount'     => $tweetCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'TWITTER', 'account_name' => $name],
        ]);

        return redirect("{$frontendUrl}/connections?connected=TWITTER&success=true");
    }

    /**
     * Complete Twitter / X connection with direct token.
     */
    public function completeTwitter(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accessToken' => 'required|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $accessToken = $validated['accessToken'];

        $userRes = Http::withToken($accessToken)->get('https://api.twitter.com/2/users/me', [
            'user.fields' => 'profile_image_url,public_metrics,username,name',
        ]);

        $userData = $userRes->json('data') ?? [];
        $userId = $userData['id'] ?? ('tw_' . substr(md5($accessToken), 0, 10));
        $name = $userData['name'] ?? 'X Creator';
        $username = $userData['username'] ?? 'x_user';
        $avatarUrl = $userData['profile_image_url'] ?? null;
        $followersCount = (int) ($userData['public_metrics']['followers_count'] ?? 0);

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'TWITTER',
            ],
            [
                'platform_account_id'    => $userId,
                'account_name'           => $name,
                'account_handle'         => '@' . $username,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
                'encrypted_access_token' => $accessToken,
                'token_expires_at'       => now()->addHours(2),
                'metadata'               => [
                    'followersCount' => $followersCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }

    // =========================================================================
    // PINTEREST OAuth Flow
    // =========================================================================

    /**
     * Start Pinterest OAuth authorization redirect flow.
     */
    public function pinterestAuthorize(Request $request)
    {
        $rawWorkspaceId = $request->query('workspaceId') ?? 'omnipublish-studio';
        $workspaceId = Workspace::resolveId($rawWorkspaceId);

        $clientId = config('services.pinterest.client_id');
        $redirectUri = config('services.pinterest.redirect_uri') ?? url('/api/v1/oauth/pinterest/callback');

        if (!$clientId) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'redirectUrl' => null,
                    'message' => 'PINTEREST_APP_ID is not configured in backend/.env. Please configure Pinterest Developer credentials.',
                ],
            ]);
        }

        $state = Str::uuid()->toString();
        Cache::put("pinterest_oauth_{$state}", [
            'workspace_id' => $workspaceId,
        ], now()->addMinutes(10));

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'boards:read,pins:read,pins:write,user_accounts:read',
            'state'         => $state,
        ];

        $redirectUrl = 'https://www.pinterest.com/oauth/?' . http_build_query($params);

        return response()->json([
            'success' => true,
            'data'    => [
                'redirectUrl' => $redirectUrl,
            ],
        ]);
    }

    /**
     * Handle Pinterest OAuth redirect callback.
     */
    public function pinterestCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($error || !$code) {
            $msg = urlencode($errorDescription ?: $error ?: 'Pinterest authorization was cancelled.');
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $cachedData = Cache::pull("pinterest_oauth_{$state}");
        if (!$cachedData) {
            return redirect("{$frontendUrl}/connections?error=" . urlencode('OAuth session expired. Please try connecting again.'));
        }

        $workspaceId  = $cachedData['workspace_id'];
        $clientId     = config('services.pinterest.client_id');
        $clientSecret = config('services.pinterest.client_secret');
        $redirectUri  = config('services.pinterest.redirect_uri') ?? url('/api/v1/oauth/pinterest/callback');

        $tokenRes = Http::asForm()->withBasicAuth($clientId, $clientSecret)->post('https://api.pinterest.com/v5/oauth/token', [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($tokenRes->failed() || empty($tokenRes->json('access_token'))) {
            $msg = urlencode('Failed to exchange Pinterest authorization code: ' . ($tokenRes->json('message') ?? 'Unknown error'));
            return redirect("{$frontendUrl}/connections?error={$msg}");
        }

        $accessToken = $tokenRes->json('access_token');
        $refreshToken = $tokenRes->json('refresh_token');
        $expiresIn = $tokenRes->json('expires_in') ?? (30 * 86400);

        // Fetch user account info
        $userRes = Http::withToken($accessToken)->get('https://api.pinterest.com/v5/user_account');
        $userData = $userRes->json() ?? [];

        $username = $userData['username'] ?? 'pinterest_creator';
        $pinAccountId = $userData['id'] ?? ('pin_' . substr(md5($accessToken), 0, 10));
        $avatarUrl = $userData['profile_image'] ?? null;
        $followerCount = (int) ($userData['follower_count'] ?? 0);
        $pinCount = (int) ($userData['pin_count'] ?? 0);

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'PINTEREST',
            ],
            [
                'platform_account_id'    => $pinAccountId,
                'account_name'           => ucfirst($username) . ' Studio',
                'account_handle'         => '@' . $username,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read'],
                'encrypted_access_token' => $accessToken,
                'encrypted_refresh_token'=> $refreshToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'metadata'               => [
                    'followersCount' => $followerCount,
                    'pinCount'       => $pinCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        AuditEvent::create([
            'workspace_id'  => $workspaceId,
            'action'        => 'ACCOUNT_CONNECTED',
            'resource_type' => 'SocialAccount',
            'resource_id'   => $account->id,
            'metadata'      => ['platform' => 'PINTEREST', 'account_name' => $username],
        ]);

        return redirect("{$frontendUrl}/connections?connected=PINTEREST&success=true");
    }

    /**
     * Complete Pinterest connection with direct token.
     */
    public function completePinterest(Request $request)
    {
        $validated = $request->validate([
            'workspaceId' => 'required|string',
            'accessToken' => 'required|string',
        ]);

        $workspaceId = Workspace::resolveId($validated['workspaceId']);
        $accessToken = $validated['accessToken'];

        $userRes = Http::withToken($accessToken)->get('https://api.pinterest.com/v5/user_account');
        $userData = $userRes->json() ?? [];

        $username = $userData['username'] ?? 'pinterest_creator';
        $pinAccountId = $userData['id'] ?? ('pin_' . substr(md5($accessToken), 0, 10));
        $avatarUrl = $userData['profile_image'] ?? null;
        $followerCount = (int) ($userData['follower_count'] ?? 0);
        $pinCount = (int) ($userData['pin_count'] ?? 0);

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'platform'     => 'PINTEREST',
            ],
            [
                'platform_account_id'    => $pinAccountId,
                'account_name'           => ucfirst($username) . ' Studio',
                'account_handle'         => '@' . $username,
                'avatar_url'             => $avatarUrl,
                'status'                 => 'ACTIVE',
                'scopes'                 => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read'],
                'encrypted_access_token' => $accessToken,
                'token_expires_at'       => now()->addDays(30),
                'metadata'               => [
                    'followersCount' => $followerCount,
                    'pinCount'       => $pinCount,
                    'connected_at'   => now()->toIso8601String(),
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $account,
        ]);
    }
}
