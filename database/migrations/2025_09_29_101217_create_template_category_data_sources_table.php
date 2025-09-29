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
        Schema::create('template_category_data_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_category_id');
            $table->foreign('template_category_id')->references('id')->on('template_categories');
            $table->unsignedBigInteger('datasource_id')->nullable();
            $table->foreign('datasource_id')->references('id')->on('data_sources');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->integer('source_db_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_category_data_sources');
    }
};
