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
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            // Change auth_type to support more options
            $table->string('auth_type', 50)->default('static_token')->change();

            // For JWT-based auth (Kling)
            $table->string('auth_secret_key_env')->nullable()->after('auth_value_from_env');

            // JWT configuration
            $table->integer('jwt_expiry_seconds')->nullable()->after('auth_secret_key_env');
            $table->integer('jwt_nbf_offset_seconds')->nullable()->after('jwt_expiry_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            $table->dropColumn(['auth_secret_key_env', 'jwt_expiry_seconds', 'jwt_nbf_offset_seconds']);
        });
    }
};
