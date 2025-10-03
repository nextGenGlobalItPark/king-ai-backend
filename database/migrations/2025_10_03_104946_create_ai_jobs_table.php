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
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('task_id')->unique()->nullable();
            $table->string('feature');
            $table->unsignedBigInteger('data_source_id');
            $table->foreign('data_source_id')->references('id')->on('ai_data_sources');
            $table->json('input');
            $table->json('result')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
