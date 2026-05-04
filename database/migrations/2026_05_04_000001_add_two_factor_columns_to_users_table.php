<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add columns supporting TOTP-based 2FA.
     *
     * - two_factor_secret: encrypted TOTP secret. Nullable until the user opts in.
     * - two_factor_recovery_codes: encrypted JSON array of *hashed* recovery codes.
     *   We never store recovery codes in plaintext; codes are shown once at
     *   generation and then forgotten by the server.
     * - two_factor_confirmed_at: set after the user successfully verifies their
     *   first code. Until this is set, 2FA is "staged" — login is NOT yet gated
     *   by it, so a user can't lock themselves out by abandoning setup partway.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
