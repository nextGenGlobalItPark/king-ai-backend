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
        Schema::create('ai_feature_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_feature_id')->constrained('ai_features')->onDelete('cascade');
            $table->unsignedBigInteger('data_source_id');
            $table->foreign('data_source_id')->references('id')->on('data_sources')->onDelete('cascade');
            $table->string('endpoint');  // e.g., 'https://api.vidu.com/ent/v2/text2video'
            $table->string('method')->default('POST');
            $table->string('auth_type')->default('token');  // 'token', 'bearer'
            $table->string('auth_header_key')->nullable();  // e.g., 'Authorization'
            $table->string('auth_value_from_env');  // e.g., 'VIDU_API_KEY'
            $table->string('content_type')->default('application/json');
            $table->string('body_format')->default('json');  // 'json', 'form-data'
            $table->json('standard_schema')->nullable();  // {"required": ["prompt"], "optional": ["duration", "aspect_ratio"]}
            $table->json('param_mapping');  // {"prompt": "prompt", "duration": "duration"}
            $table->json('default_params')->nullable();  // {"model": "viduq1"}
            $table->json('extra_params')->nullable();  // {"off_peak": false}
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);  // For fallback ordering if multiple active
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['ai_feature_id', 'data_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ai_feature_configs');
        Schema::enableForeignKeyConstraints();
    }
};
