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

        if ($appId && $appSecret) {
            $exchangeRes = Http::get('https://graph.facebook.com/v21.0/oauth/access_token', [
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
        $pagesRes = Http::get('https://graph.facebook.com/v21.0/me/accounts', [
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

            $igLinkRes = Http::get("https://graph.facebook.com/v21.0/{$pageId}", [
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
        $igProfileRes = Http::get("https://graph.facebook.com/v21.0/{$igAccountId}", [
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
                'scopes'                 => ['instagram_basic', 'instagram_content_publish', 'pages_show_list'],
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
                'success' => false,
                'message' => 'TIKTOK_CLIENT_KEY is not configured in backend/.env. Please configure TikTok developer credentials.',
            ], 422);
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
}
