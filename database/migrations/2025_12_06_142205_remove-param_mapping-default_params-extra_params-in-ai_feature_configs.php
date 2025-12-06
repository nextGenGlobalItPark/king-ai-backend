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
            $table->dropColumn('param_mapping');
            $table->dropColumn('default_params');
            $table->dropColumn('extra_params');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_feature_configs', function (Blueprint $table) {
            $table->json('param_mapping')->after('standard_schema');
            $table->json('default_params')->after('param_mapping');
            $table->json('response_mapping')->after('default_params');
        });
    }
};
