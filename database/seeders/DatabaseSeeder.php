<?php

namespace Database\Seeders;

use App\Models\AiFeature;
use Illuminate\Database\Seeder;

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
            TemplateCategoriesSeeder::class,
//            AiFeaturesSeeder::class,
//            AiFeatureConfigsSeeder::class
        ]);

    }
}
