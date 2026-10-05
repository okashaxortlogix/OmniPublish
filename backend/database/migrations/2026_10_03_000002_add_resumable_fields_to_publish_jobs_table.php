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
            $table->text('upload_session_url')->nullable()->after('remote_post_url');
            $table->unsignedBigInteger('bytes_uploaded')->default(0)->after('upload_session_url');
            $table->unsignedBigInteger('total_bytes')->default(0)->after('bytes_uploaded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('publish_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'upload_session_url',
                'bytes_uploaded',
                'total_bytes',
            ]);
        });
    }
};
