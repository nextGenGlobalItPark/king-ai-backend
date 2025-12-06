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
        Schema::create('vendor_status_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_source_id')->unique();
            $table->foreign('data_source_id')->references('id')->on('data_sources')->onDelete('cascade');
            $table->json('status_mapping');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_status_mappings');
    }
};
