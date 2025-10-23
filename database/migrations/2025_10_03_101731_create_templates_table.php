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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('template_category_id')->nullable();
            $table->foreign('template_category_id')->references('id')->on('template_categories');
            $table->integer('credit_cost')->nullable();
            $table->unsignedBigInteger('ai_feature_id')->nullable();
            $table->foreign('ai_feature_id')->references('id')->on('ai_features');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->boolean('is_active')->default(true);
            $table->enum('state', ['test', 'live'])->default('test')->comment("value is of either test or live");
            $table->integer('sequence')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
