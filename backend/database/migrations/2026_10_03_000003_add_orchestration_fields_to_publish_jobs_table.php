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
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->uuid('media_asset_id')->nullable()->after('social_account_id');
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('retry_at')->nullable()->after('scheduled_at');
            $table->timestamp('started_at')->nullable()->after('retry_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->timestamp('last_heartbeat_at')->nullable()->after('completed_at');
            $table->string('idempotency_key', 128)->nullable()->after('last_heartbeat_at');
            $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            $table->json('destination_config')->nullable()->after('correlation_id');
            $table->json('validation_errors')->nullable()->after('destination_config');
            $table->json('provider_error')->nullable()->after('validation_errors');

            // Foreign key to media_assets
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->onDelete('set null');

            // Production indexes for orchestration, scheduling, and reconciliation
            $table->index(['workspace_id', 'status', 'scheduled_at'], 'idx_pub_jobs_ws_status_sched');
            $table->index(['status', 'retry_at'], 'idx_pub_jobs_status_retry');
            $table->index(['status', 'last_heartbeat_at'], 'idx_pub_jobs_status_heartbeat');
            $table->index('idempotency_key', 'idx_pub_jobs_idempotency');
            $table->index('correlation_id', 'idx_pub_jobs_correlation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropForeign(['media_asset_id']);
            $table->dropIndex('idx_pub_jobs_ws_status_sched');
            $table->dropIndex('idx_pub_jobs_status_retry');
            $table->dropIndex('idx_pub_jobs_status_heartbeat');
            $table->dropIndex('idx_pub_jobs_idempotency');
            $table->dropIndex('idx_pub_jobs_correlation');

            $table->dropColumn([
                'media_asset_id',
                'scheduled_at',
                'retry_at',
                'started_at',
                'completed_at',
                'last_heartbeat_at',
                'idempotency_key',
                'correlation_id',
                'destination_config',
                'validation_errors',
                'provider_error',
            ]);
        });
    }
};
