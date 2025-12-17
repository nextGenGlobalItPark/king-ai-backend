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
        // Create pivot table for many-to-many relationship
        Schema::create('template_category_template', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('templates')
                ->onDelete('cascade');
            $table->foreignId('template_category_id')
                ->constrained('template_categories')
                ->onDelete('cascade');
            $table->timestamps();

            // Prevent duplicate associations
            $table->unique(['template_id', 'template_category_id'], 'template_category_unique');
        });

        // Drop the old template_category_id column from templates table
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['template_category_id']);
            $table->dropColumn('template_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the old column
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('template_category_id')
                ->nullable()
                ->after('name')
                ->constrained('template_categories');
        });

        // Drop the pivot table
        Schema::dropIfExists('template_category_template');
    }
};