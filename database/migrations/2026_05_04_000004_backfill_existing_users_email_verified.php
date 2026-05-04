<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Grandfather any existing users — i.e., users who already exist in
     * production at the moment this migration runs — as having a verified
     * email. Without this step, deploying the email-verification feature
     * would instantly lock every existing admin (and every existing API
     * user) out of the system, since their email_verified_at is null.
     *
     * This intentionally runs AFTER the user table migrations have settled
     * for the new feature columns. New users from this point on go
     * through the normal verification flow.
     *
     * Idempotent: marking already-verified users as verified is a no-op.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // No-op on rollback. We don't try to "un-verify" because we can't
        // distinguish users who were verified by this backfill from users
        // who legitimately verified later.
    }
};
