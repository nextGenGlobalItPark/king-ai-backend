<?php

namespace Database\Seeders;

use App\Models\AiFeature;
use App\Models\DataSource;
use App\Models\TemplateCategory;
use App\Models\TemplateCategoryDataSource;
use Illuminate\Database\Seeder;

class TemplateCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'template_category_name' => 'Funny',
                'template_category_slug' => 'funny',
                'created_by' => 1,
            ],
            [
                'template_category_name' => 'Angry',
                'template_category_slug' => 'angry',
                'created_by' => 1,
            ],
            [
                'template_category_name' => 'Trending',
                'template_category_slug' => 'trending',
                'created_by' => 1,
            ]
        ];

        $kingAiId = DataSource::where('name', 'King-ai')->first()->id;
        foreach ($categories as $category) {
            $category = TemplateCategory::create($category);
            TemplateCategoryDataSource::create([
                    'datasource_id' => $kingAiId,
                    'template_category_id' => $category->id,
                    'created_by' => 1,
                ]
            );
        }
    }
}