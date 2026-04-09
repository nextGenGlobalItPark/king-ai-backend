<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds callback/webhook support fields to ai_feature_configs:
     * - completion_mode: 'polling' (default, current behavior) or 'callback' (vendor webhooks)
     * - webhook_secret: optional secret for verifying incoming webhook signatures
     */
    public function up(): void
    {
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            $table->string('completion_mode')->default('polling')->after('status_check_endpoint');
            $table->string('webhook_secret')->nullable()->after('completion_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            $table->dropColumn(['completion_mode', 'webhook_secret']);
        });
    }
};
