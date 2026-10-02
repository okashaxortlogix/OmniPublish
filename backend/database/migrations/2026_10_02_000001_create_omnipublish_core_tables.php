<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Workspaces
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 2. Workspace Memberships
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'])->default('EDITOR');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['workspace_id', 'user_id']);
        });

        // 3. Social Accounts
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('platform'); // YOUTUBE, TIKTOK, INSTAGRAM, etc.
            $table->string('platform_account_id');
            $table->string('account_name');
            $table->string('account_handle')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status')->default('ACTIVE'); // ACTIVE, EXPIRED, REVOKED, ACTION_REQUIRED
            $table->json('scopes')->nullable();
            $table->text('encrypted_access_token')->nullable();
            $table->text('encrypted_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->index(['workspace_id', 'platform']);
        });

        // 4. Media Assets
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('filename');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->text('storage_path');
            $table->text('thumbnail_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('aspect_ratio')->nullable(); // 9:16, 16:9, 1:1
            $table->string('codec')->nullable();
            $table->string('status')->default('UPLOADING'); // UPLOADING, PROCESSING, READY, FAILED, DELETED
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->index(['workspace_id', 'status']);
        });

        // 5. Content Items
        Schema::create('content_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('media_asset_id')->nullable();
            $table->json('global_metadata'); // title, description, caption, hashtags, tags, thumbnail_url, visibility
            $table->json('platform_overrides')->nullable(); // per-platform metadata overrides
            $table->json('target_platforms'); // array of selected platforms
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->onDelete('set null');
        });

        // 6. Publish Batches
        Schema::create('publish_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('content_item_id');
            $table->string('status')->default('QUEUED'); // DRAFT, VALIDATING, QUEUED, RUNNING, COMPLETED, PARTIAL_SUCCESS, FAILED, CANCELLED
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('content_item_id')->references('id')->on('content_items')->onDelete('cascade');
            $table->index(['workspace_id', 'status']);
            $table->index('scheduled_for');
        });

        // 7. Publish Jobs (per-platform independent destination jobs)
        Schema::create('publish_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('publish_batch_id');
            $table->uuid('workspace_id');
            $table->uuid('social_account_id');
            $table->string('platform');
            $table->string('status')->default('QUEUED'); // CREATED, QUEUED, UPLOADING, PROCESSING, PUBLISHED, FAILED, AUTH_REQUIRED, RATE_LIMITED, CANCELLED, RETRYING
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('remote_post_id')->nullable();
            $table->text('remote_post_url')->nullable();
            $table->string('failure_category')->nullable(); // AUTH_REQUIRED, INVALID_MEDIA, RATE_LIMITED, etc.
            $table->text('user_facing_error_message')->nullable();
            $table->text('internal_error_details')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->foreign('publish_batch_id')->references('id')->on('publish_batches')->onDelete('cascade');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->index(['workspace_id', 'status']);
            $table->index(['publish_batch_id', 'platform']);
        });

        // 8. Publish Attempts (History for audit and debugging)
        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('publish_job_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->string('error_category')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('publish_job_id')->references('id')->on('publish_jobs')->onDelete('cascade');
        });

        // 9. Audit Events
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action'); // ACCOUNT_CONNECTED, PUBLISH_REQUESTED, etc.
            $table->string('resource_type');
            $table->string('resource_id');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['workspace_id', 'action']);
        });

        // 10. Personal Access / Sanctum Tokens (Lightweight UUID-based)
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('publish_attempts');
        Schema::dropIfExists('publish_jobs');
        Schema::dropIfExists('publish_batches');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
