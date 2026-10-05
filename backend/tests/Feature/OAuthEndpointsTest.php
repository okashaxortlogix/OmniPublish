<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\SocialAccount;
use App\Services\TokenRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class OAuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'alex@omnipublish.io',
        ]);

        $this->workspace = Workspace::create([
            'name' => 'OmniPublish Studio',
            'slug' => 'omnipublish-studio',
            'owner_id' => $this->user->id,
        ]);
    }

    public function test_youtube_authorize_generates_google_oauth_url(): void
    {
        Config::set('services.google.client_id', 'google_client_id_test.apps.googleusercontent.com');
        Config::set('services.google.redirect_uri', 'http://localhost:8001/api/v1/oauth/google/callback');

        $response = $this->getJson('/api/v1/oauth/youtube/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $redirectUrl);
        $this->assertStringContainsString('client_id=google_client_id_test', $redirectUrl);
        $this->assertStringContainsString('youtube.upload', $redirectUrl);
        $this->assertStringContainsString('access_type=offline', $redirectUrl);
    }

    public function test_youtube_oauth_requires_access_token(): void
    {
        $response = $this->postJson('/api/v1/oauth/complete/youtube', [
            'workspaceId' => 'omnipublish-studio',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['accessToken']);
    }

    public function test_instagram_oauth_requires_access_token(): void
    {
        $response = $this->postJson('/api/v1/oauth/complete/instagram', [
            'workspaceId' => 'omnipublish-studio',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['accessToken']);
    }

    public function test_tiktok_authorize_endpoint_generates_pkce_url(): void
    {
        Config::set('services.tiktok.client_key', 'test_client_key_123');
        Config::set('services.tiktok.redirect_uri', 'http://localhost:8001/api/v1/oauth/tiktok/callback');

        $response = $this->getJson('/api/v1/oauth/tiktok/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://www.tiktok.com/v2/auth/authorize', $redirectUrl);
        $this->assertStringContainsString('client_key=test_client_key_123', $redirectUrl);
        $this->assertStringContainsString('code_challenge=', $redirectUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $redirectUrl);
    }

    public function test_youtube_oauth_creates_social_account_with_real_metrics(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [
                    [
                        'id' => 'UC_TestLiveChannel123',
                        'snippet' => [
                            'title' => 'Live Test Channel',
                            'customUrl' => '@livetest',
                            'thumbnails' => [
                                'high' => ['url' => 'https://example.com/avatar.jpg'],
                            ],
                        ],
                        'statistics' => [
                            'subscriberCount' => '48200',
                            'videoCount' => '112',
                            'viewCount' => '950000',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/oauth/complete/youtube', [
            'workspaceId' => 'omnipublish-studio',
            'accessToken' => 'ya29.test_google_access_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.account_name', 'Live Test Channel');
        $response->assertJsonPath('data.account_handle', '@livetest');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.metadata.subscriberCount', 48200);

        $this->assertDatabaseHas('social_accounts', [
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_TestLiveChannel123',
            'account_name' => 'Live Test Channel',
        ]);
    }

    public function test_token_refresh_service_preserves_valid_token(): void
    {
        $account = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_ValidTokenChannel',
            'account_name' => 'Valid Channel',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'live_token_123',
            'token_expires_at' => now()->addDays(5),
        ]);

        $service = new TokenRefreshService();
        $token = $service->refreshIfExpired($account);

        $this->assertEquals('live_token_123', $token);
    }

    public function test_facebook_authorize_generates_meta_oauth_url(): void
    {
        Config::set('services.facebook.client_id', 'fb_app_id_test');
        Config::set('services.facebook.redirect_uri', 'http://localhost:8001/api/v1/oauth/facebook/callback');

        $response = $this->getJson('/api/v1/oauth/facebook/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://www.facebook.com/v21.0/dialog/oauth', $redirectUrl);
        $this->assertStringContainsString('client_id=fb_app_id_test', $redirectUrl);
        $this->assertStringContainsString('pages_manage_posts', $redirectUrl);
    }

    public function test_linkedin_authorize_generates_linkedin_oauth_url(): void
    {
        Config::set('services.linkedin.client_id', 'linkedin_client_id_test');
        Config::set('services.linkedin.redirect_uri', 'http://localhost:8001/api/v1/oauth/linkedin/callback');

        $response = $this->getJson('/api/v1/oauth/linkedin/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://www.linkedin.com/oauth/v2/authorization', $redirectUrl);
        $this->assertStringContainsString('client_id=linkedin_client_id_test', $redirectUrl);
        $this->assertStringContainsString('w_member_social', $redirectUrl);
    }

    public function test_twitter_authorize_generates_pkce_url(): void
    {
        Config::set('services.twitter.client_id', 'twitter_client_id_test');
        Config::set('services.twitter.redirect_uri', 'http://localhost:8001/api/v1/oauth/twitter/callback');

        $response = $this->getJson('/api/v1/oauth/twitter/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://twitter.com/i/oauth2/authorize', $redirectUrl);
        $this->assertStringContainsString('client_id=twitter_client_id_test', $redirectUrl);
        $this->assertStringContainsString('code_challenge=', $redirectUrl);
    }

    public function test_pinterest_authorize_generates_pinterest_oauth_url(): void
    {
        Config::set('services.pinterest.client_id', 'pinterest_app_id_test');
        Config::set('services.pinterest.redirect_uri', 'http://localhost:8001/api/v1/oauth/pinterest/callback');

        $response = $this->getJson('/api/v1/oauth/pinterest/authorize?workspaceId=omnipublish-studio');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['redirectUrl'],
        ]);

        $redirectUrl = $response->json('data.redirectUrl');
        $this->assertStringContainsString('https://www.pinterest.com/oauth', $redirectUrl);
        $this->assertStringContainsString('client_id=pinterest_app_id_test', $redirectUrl);
        $this->assertStringContainsString('pins:write', urldecode($redirectUrl));
    }

    public function test_complete_facebook_creates_social_account(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id' => 'fb_page_123',
                        'name' => 'OmniPublish Official Page',
                        'fan_count' => 12400,
                        'picture' => ['data' => ['url' => 'https://example.com/fb.jpg']],
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/oauth/complete/facebook', [
            'workspaceId' => 'omnipublish-studio',
            'accessToken' => 'mock_fb_access_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.platform', 'FACEBOOK');
        $response->assertJsonPath('data.account_name', 'OmniPublish Official Page');
    }

    public function test_complete_linkedin_creates_social_account(): void
    {
        Http::fake([
            'https://api.linkedin.com/v2/userinfo*' => Http::response([
                'sub' => 'li_sub_999',
                'name' => 'Alex Rivera',
                'picture' => 'https://example.com/alex.jpg',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/oauth/complete/linkedin', [
            'workspaceId' => 'omnipublish-studio',
            'accessToken' => 'mock_linkedin_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.platform', 'LINKEDIN');
        $response->assertJsonPath('data.account_name', 'Alex Rivera');
    }

    public function test_complete_twitter_creates_social_account(): void
    {
        Http::fake([
            'https://api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => 'tw_98765',
                    'name' => 'OmniPublish Dev',
                    'username' => 'omnipublish_dev',
                    'profile_image_url' => 'https://example.com/tw.jpg',
                    'public_metrics' => [
                        'followers_count' => 5400,
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/oauth/complete/twitter', [
            'workspaceId' => 'omnipublish-studio',
            'accessToken' => 'mock_twitter_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.platform', 'TWITTER');
        $response->assertJsonPath('data.account_name', 'OmniPublish Dev');
        $response->assertJsonPath('data.account_handle', '@omnipublish_dev');
    }

    public function test_complete_pinterest_creates_social_account(): void
    {
        Http::fake([
            'https://api.pinterest.com/v5/user_account*' => Http::response([
                'id' => 'pin_111',
                'username' => 'omnipublish',
                'profile_image' => 'https://example.com/pin.jpg',
                'follower_count' => 3200,
                'pin_count' => 88,
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/oauth/complete/pinterest', [
            'workspaceId' => 'omnipublish-studio',
            'accessToken' => 'mock_pinterest_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.platform', 'PINTEREST');
        $response->assertJsonPath('data.account_name', 'Omnipublish Studio');
    }
}
