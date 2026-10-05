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
        // 1. Enrich media_assets with stream technical metadata & processing stage
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('processing_stage')->nullable()->after('status'); // QUEUED, ANALYZING, PROCESSING, GENERATING_THUMBNAIL, CREATING_DERIVATIVES, READY, FAILED
            $table->unsignedBigInteger('bitrate')->nullable()->after('codec');
            $table->string('frame_rate')->nullable()->after('bitrate');
            $table->string('video_codec')->nullable()->after('frame_rate');
            $table->string('audio_codec')->nullable()->after('video_codec');
            $table->unsignedSmallInteger('audio_channels')->nullable()->after('audio_codec');
            $table->unsignedInteger('audio_sample_rate')->nullable()->after('audio_channels');
            $table->string('pixel_format')->nullable()->after('audio_sample_rate');
            $table->string('container_format')->nullable()->after('pixel_format');
            $table->timestamp('processing_started_at')->nullable()->after('container_format');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
        });

        // 2. Media Derivatives (Thumbnails, Normalized Masters, Platform Derivatives)
        Schema::create('media_derivatives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_asset_id');
            $table->uuid('workspace_id');
            $table->string('derivative_type'); // THUMBNAIL, NORMALIZED_MASTER, PLATFORM_DERIVATIVE
            $table->string('target_platform')->nullable(); // YOUTUBE, TIKTOK, INSTAGRAM, etc.
            $table->text('storage_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bitrate')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status')->default('READY'); // PROCESSING, READY, FAILED
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->onDelete('cascade');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->index(['media_asset_id', 'derivative_type']);
            $table->index(['workspace_id', 'target_platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_derivatives');

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn([
                'processing_stage',
                'bitrate',
                'frame_rate',
                'video_codec',
                'audio_codec',
                'audio_channels',
                'audio_sample_rate',
                'pixel_format',
                'container_format',
                'processing_started_at',
                'processing_completed_at',
            ]);
        });
    }
};
