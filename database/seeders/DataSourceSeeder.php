<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataSourceSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('data_sources')->truncate();
        Schema::enableForeignKeyConstraints();

        DataSource::create([
            'name'  => 'Vidu',
            'url' => 'https://platform.vidu.com/',
            'created_by' => 1
        ]);

        DataSource::create([
            'name'  => 'Kling',
            'url' => 'https://klingai.com/',
            'created_by' => 1
        ]);
    }
}
