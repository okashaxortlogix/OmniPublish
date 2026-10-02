<?php

namespace App\Services;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenRefreshService
{
    public function refreshIfExpired(SocialAccount $account): string
    {
        // If token has not expired and has at least 5 minutes remaining, return it
        if ($account->token_expires_at && $account->token_expires_at->isAfter(now()->addMinutes(5))) {
            return $account->encrypted_access_token ?? '';
        }

        // If no refresh token and not a Google bearer token, return existing token
        if (empty($account->encrypted_refresh_token) && !str_starts_with($account->encrypted_access_token ?? '', 'ya29.')) {
            return $account->encrypted_access_token ?? '';
        }

        try {
            return match ($account->platform) {
                'YOUTUBE'   => $this->refreshGoogle($account),
                'INSTAGRAM' => $this->refreshMeta($account),
                'TIKTOK'    => $this->refreshTikTok($account),
                default     => $account->encrypted_access_token ?? '',
            };
        } catch (\Throwable $e) {
            Log::warning("Token refresh failed for {$account->platform} ({$account->id}): " . $e->getMessage());
            $account->update(['status' => 'EXPIRED']);
            return $account->encrypted_access_token ?? '';
        }
    }

    protected function refreshGoogle(SocialAccount $account): string
    {
        $refreshToken = $account->encrypted_refresh_token;
        if (!$refreshToken) {
            return $account->encrypted_access_token ?? '';
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (!$clientId || !$clientSecret) {
            return $account->encrypted_access_token ?? '';
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $newAccessToken = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 3600;

            $account->update([
                'encrypted_access_token' => $newAccessToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'status'                 => 'ACTIVE',
            ]);

            return $newAccessToken;
        }

        throw new \RuntimeException("Google token refresh failed: " . $response->body());
    }

    protected function refreshMeta(SocialAccount $account): string
    {
        $currentAccessToken = $account->encrypted_access_token;
        if (!$currentAccessToken) {
            return '';
        }

        $clientId = config('services.facebook.client_id');
        $clientSecret = config('services.facebook.client_secret');

        if (!$clientId || !$clientSecret) {
            return $currentAccessToken;
        }

        $response = Http::get('https://graph.facebook.com/v21.0/oauth/access_token', [
            'grant_type'        => 'fb_extend_sso_token',
            'client_id'         => $clientId,
            'client_secret'     => $clientSecret,
            'fb_exchange_token' => $currentAccessToken,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $newAccessToken = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? (60 * 86400);

            $account->update([
                'encrypted_access_token' => $newAccessToken,
                'token_expires_at'       => now()->addSeconds($expiresIn),
                'status'                 => 'ACTIVE',
            ]);

            return $newAccessToken;
        }

        throw new \RuntimeException("Meta token refresh failed: " . $response->body());
    }

    protected function refreshTikTok(SocialAccount $account): string
    {
        $refreshToken = $account->encrypted_refresh_token;
        if (!$refreshToken) {
            return $account->encrypted_access_token ?? '';
        }

        $clientKey = config('services.tiktok.client_key');
        $clientSecret = config('services.tiktok.client_secret');

        if (!$clientKey || !$clientSecret) {
            return $account->encrypted_access_token ?? '';
        }

        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key'    => $clientKey,
            'client_secret' => $clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->successful()) {
            $data = $response->json('data') ?? $response->json();
            $newAccessToken = $data['access_token'] ?? null;
            if ($newAccessToken) {
                $newRefreshToken = $data['refresh_token'] ?? $refreshToken;
                $expiresIn = $data['expires_in'] ?? 86400;

                $account->update([
                    'encrypted_access_token'  => $newAccessToken,
                    'encrypted_refresh_token' => $newRefreshToken,
                    'token_expires_at'        => now()->addSeconds($expiresIn),
                    'status'                  => 'ACTIVE',
                ]);

                return $newAccessToken;
            }
        }

        throw new \RuntimeException("TikTok token refresh failed: " . $response->body());
    }
}
