<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add metadata JSON column to templates for user-facing display info.
     *
     * Stores: description, output_type, preview_video, options (resolution/duration/etc),
     * input_fields (what the user needs to provide), and guide (tips/notes/limitations).
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('credit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
