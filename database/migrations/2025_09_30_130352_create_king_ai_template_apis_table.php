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
        Schema::create('king_ai_template_apis', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id');
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('cascade');
            $table->unsignedBigInteger('data_source_id');
            $table->foreign('data_source_id')->references('id')->on('data_sources');
            $table->string('model')->nullable();
            $table->string('style')->default('general')->nullable();
            $table->string('images')->nullable();
            $table->string('prompt')->nullable();
            $table->float('duration',1)->nullable()->default(5);
            $table->integer('seed')->nullable()->default(0);
            $table->string('aspect_ratio')->default('16:9')->nullable();
            $table->string('bgm')->default('false')->nullable();
            $table->string('resolution')->nullable()->default('1080p');
            $table->string('movement_amplitude')->nullable()->default('auto');
            $table->string('off_peak')->default('false')->nullable();
            $table->string('payload')->nullable();
            $table->string('request_url');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('king_ai_template_apis');
    }
};
