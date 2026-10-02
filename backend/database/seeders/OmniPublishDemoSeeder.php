<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\SocialAccount;
use App\Models\MediaAsset;
use App\Models\ContentItem;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OmniPublishDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Demo User
        $user = User::updateOrCreate(
            ['email' => 'alex@omnipublish.io'],
            [
                'name' => 'Alex Vance',
                'password' => Hash::make('Password123!'),
            ]
        );

        // 2. Demo Workspace
        $workspace = Workspace::updateOrCreate(
            ['slug' => 'omnipublish-studio'],
            [
                'name' => 'OmniPublish Studio',
                'owner_id' => $user->id,
            ]
        );

        WorkspaceMember::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['role' => 'OWNER']
        );

        // 3. Social Accounts
        $yt = SocialAccount::updateOrCreate(
            ['workspace_id' => $workspace->id, 'platform' => 'YOUTUBE'],
            [
                'platform_account_id' => 'UC_AlexVanceStudio',
                'account_name' => 'Alex Vance Tech',
                'account_handle' => '@alexvance_tech',
                'avatar_url' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150',
                'status' => 'ACTIVE',
                'scopes' => ['youtube.upload', 'youtube.readonly'],
                'encrypted_access_token' => 'mock_yt_token',
                'token_expires_at' => now()->addDays(30),
            ]
        );

        $tt = SocialAccount::updateOrCreate(
            ['workspace_id' => $workspace->id, 'platform' => 'TIKTOK'],
            [
                'platform_account_id' => 'tt_creator_9821',
                'account_name' => 'Alex | AI & Coding',
                'account_handle' => '@alexv_codes',
                'avatar_url' => 'https://images.unsplash.com/photo-1570295999919-56ceb5ecca61?w=150',
                'status' => 'ACTIVE',
                'scopes' => ['video.publish', 'user.info.basic'],
                'encrypted_access_token' => 'mock_tt_token',
                'token_expires_at' => now()->addDays(25),
            ]
        );

        $ig = SocialAccount::updateOrCreate(
            ['workspace_id' => $workspace->id, 'platform' => 'INSTAGRAM'],
            [
                'platform_account_id' => '17841400293817',
                'account_name' => 'Alex Vance Studio',
                'account_handle' => '@alexvance.studio',
                'avatar_url' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=150',
                'status' => 'EXPIRED',
                'scopes' => ['instagram_content_publish', 'instagram_basic'],
                'encrypted_access_token' => 'mock_ig_token_expired',
                'token_expires_at' => now()->subHours(2),
            ]
        );

        // 4. Media Asset
        $media = MediaAsset::updateOrCreate(
            ['workspace_id' => $workspace->id, 'filename' => 'ai_developer_agent_demo.mp4'],
            [
                'original_name' => 'Zero to Production AI Demo.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 45200000,
                'storage_path' => 'videos/ai_developer_agent_demo.mp4',
                'thumbnail_path' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
                'duration_seconds' => 54,
                'width' => 1080,
                'height' => 1920,
                'aspect_ratio' => '9:16',
                'codec' => 'H.264 / AAC',
                'status' => 'READY',
            ]
        );

        // 5. Content Item & Batch 1: Demonstrating Partial Success & Retry
        $content = ContentItem::create([
            'workspace_id' => $workspace->id,
            'media_asset_id' => $media->id,
            'global_metadata' => [
                'title' => 'Building Autonomous AI Coding Agents in 2026',
                'description' => 'A complete deep-dive into autonomous Ralph Loops, GSD, and continuous verification.',
                'caption' => 'How to build production SaaS with AI in minutes! #AI #coding #developer #software',
                'hashtags' => ['AI', 'coding', 'developer', 'software'],
                'tags' => ['coding', 'ai agents', 'software engineering'],
                'visibility' => 'PUBLIC',
            ],
            'platform_overrides' => [
                'youtube' => ['madeForKids' => false, 'privacyStatus' => 'public'],
                'tiktok' => ['privacyLevel' => 'PUBLIC_TO_EVERYONE', 'disableComment' => false],
                'instagram' => ['shareToFeed' => true],
            ],
            'target_platforms' => ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $workspace->id,
            'content_item_id' => $content->id,
            'status' => 'PARTIAL_SUCCESS',
            'created_at' => now()->subMinutes(15),
        ]);

        // YouTube Success Job
        PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $workspace->id,
            'social_account_id' => $yt->id,
            'platform' => 'YOUTUBE',
            'status' => 'PUBLISHED',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'remote_post_id' => 'yt_dQw4w9WgXcQ',
            'remote_post_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'last_attempt_at' => now()->subMinutes(14),
        ]);

        // TikTok Success Job
        PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $workspace->id,
            'social_account_id' => $tt->id,
            'platform' => 'TIKTOK',
            'status' => 'PUBLISHED',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'remote_post_id' => 'tt_73928172910',
            'remote_post_url' => 'https://www.tiktok.com/@alexv_codes/video/73928172910',
            'last_attempt_at' => now()->subMinutes(12),
        ]);

        // Instagram Auth Failed Job (Ready for Retry demonstration!)
        PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $workspace->id,
            'social_account_id' => $ig->id,
            'platform' => 'INSTAGRAM',
            'status' => 'AUTH_REQUIRED',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'failure_category' => 'AUTH_REQUIRED',
            'user_facing_error_message' => 'Instagram authorization expired. Reconnect your account and retry this destination.',
            'internal_error_details' => 'OAuthException: Error validating access token: Session has expired on 2026-10-02.',
            'last_attempt_at' => now()->subMinutes(10),
        ]);
    }
}
