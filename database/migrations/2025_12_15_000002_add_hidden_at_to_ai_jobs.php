<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add hidden_at for user-facing soft-hide (separate from soft delete).
     * Users can hide items from their history without affecting admin visibility.
     */
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
