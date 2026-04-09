<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capture which template was used and its credit cost at generation time.
     *
     * - template_id: references the template the user selected
     * - credit_cost: snapshot of the cost at generation time (template cost may change later)
     */
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('feature');
            $table->foreign('template_id')->references('id')->on('templates')->nullOnDelete();
            $table->integer('credit_cost')->nullable()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn(['template_id', 'credit_cost']);
        });
    }
};
