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
            $table->string('status_check_endpoint')->nullable()->after('endpoint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            $table->dropColumn('status_check_endpoint');
        });
    }
};
