<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            AdminSeeder::class,
            DataSourceSeeder::class,
        ]);

    }
}
