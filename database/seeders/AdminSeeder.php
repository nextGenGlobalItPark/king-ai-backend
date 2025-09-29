<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('users')->truncate();
        Schema::enableForeignKeyConstraints();

        User::create(
            [
                'name' => 'Admin',
                'email' => 'admin@king-ai.com',
                'password' => bcrypt('12345678'),
                'email_verified_at' => now(),
            ],
        );
    }
}
