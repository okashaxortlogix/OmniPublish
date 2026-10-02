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
}
