<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One-time email verification codes.
     *
     * Design:
     * - We store a bcrypt HASH of the 6-digit code, never plaintext. An
     *   attacker with DB read access still can't observe in-flight codes.
     * - Each user has at most one *active* (unexpired, unconsumed) code at
     *   a time. Generating a new one supersedes the old one (we just
     *   replace the row by user_id), so a leaked old code can't be
     *   re-used after the user requested a fresh one.
     * - `attempts` increments on each failed verification; once it reaches
     *   the max (5), further attempts on this code are rejected and the
     *   user has to request a new one. This prevents brute-forcing the
     *   6-digit space (1M combinations).
     * - We do NOT track separate "consumed_at" — once verified, the row is
     *   deleted. The user's `email_verified_at` is the source of truth.
     */
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique() // one active code per user
                ->constrained()
                ->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
