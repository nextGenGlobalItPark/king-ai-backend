<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OAuth users have no password — they authenticate exclusively through
     * Google or Apple via Supabase. Making this column nullable allows us to
     * store null for those accounts rather than generating a random throwaway
     * password that could never be used.
     *
     * The User::isOauthOnly() helper returns true when password is null,
     * which is used to gate password-sensitive operations (2FA disable,
     * recovery-code regeneration) that require password reconfirmation.
     *
     * An OAuth user who later uses forgot-password to set a real password
     * is no longer OAuth-only and can use those operations normally.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
