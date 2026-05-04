<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `must_change_password` — set to true when an admin creates a user with
     * a temporary password. The login flow then issues a one-time
     * password-change token instead of a normal sanctum token, forcing the
     * user to set their own password before they can do anything else.
     *
     * After a successful password change, the flag is cleared.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')
                ->default(false)
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
