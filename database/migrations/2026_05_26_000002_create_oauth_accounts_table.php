<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a local User to one or more external OAuth identities.
     *
     * Design decisions:
     * - Separate table (not columns on users): a user can link both Google
     *   and Apple to the same account. Columns would require a second set
     *   per provider and break down immediately.
     * - provider_id is the Supabase user UUID returned by Supabase Auth after
     *   the PKCE exchange — it is stable across logins for the same identity.
     * - unique(['provider', 'provider_id']): one row per OAuth identity. Two
     *   accounts cannot share the same Google identity.
     * - provider_email is stored for debugging / admin visibility. The email
     *   of record for the user is always users.email; this is informational.
     * - cascadeOnDelete: if the user is deleted (soft-delete is on users, but
     *   if hard-deleted or forcefully deleted), the OAuth link is removed too.
     */
    public function up(): void
    {
        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');          // 'google' | 'apple'
            $table->string('provider_id');       // Supabase user UUID
            $table->string('provider_email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_accounts');
    }
};
