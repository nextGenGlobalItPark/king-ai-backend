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
        Schema::create('vidu_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary();
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('cascade');
            $table->integer('resource_id')->comment('Resource ID is returned by the API');
            $table->json('resource')->nullable();
            $table->string('name');
            $table->string('resolution');
            $table->string('duration');
            $table->string('aspect_ratio');
            $table->string('credit_cost');
            $table->string('scene');
            $table->unsignedBigInteger('template_category_id')->nullable();
            $table->foreign('template_category_id')->references('id')->on('template_categories');
            $table->string('cover_url');
            $table->string('type');
            $table->string('video_url');
            $table->string('image_count')->nullable();
            $table->json('image_url')->nullable();
            $table->string('person_count')->nullable();
            $table->text('attention')->nullable();
            $table->text('prompt_instruction')->nullable();
            $table->text('effect_boundary')->nullable();
            $table->text('request_body')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vidu_templates');
    }
};
