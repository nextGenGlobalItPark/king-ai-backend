<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One-time password reset codes — same shape as email_verification_codes,
     * but a SEPARATE table because the lifecycles don't overlap and mixing
     * them would muddy the audit story:
     *   - An email verification code grants "your inbox proves your identity".
     *   - A password reset code grants "you can change my password right now".
     * They expire/get consumed independently, and reusing one row for both
     * would be a footgun (e.g. issuing a fresh verify code shouldn't blow
     * away an active reset code or vice-versa).
     *
     * As with email_verification_codes, we store the BCRYPT hash of the
     * plaintext code (6 digits → ~20 bits of entropy → DB-leak resistant
     * by virtue of bcrypt cost), and we replace the row on regeneration.
     */
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique() // one active reset code per user
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
        Schema::dropIfExists('password_reset_codes');
    }
};
