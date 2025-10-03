<?php

namespace Database\Seeders;

use App\Models\AiFeature;
use Illuminate\Database\Seeder;

class AiFeaturesSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'name' => 'text2video',
                'description' => 'Generate video from text prompt',
                'created_by' => 1,
            ],
            [
                'name' => 'img2video',
                'description' => 'Generate video from image(s)',
                'created_by' => 1,
            ],
            [
                'name' => 'reference2video',
                'description' => 'Generate video from reference images',
                'created_by' => 1,
            ],
            [
                'name' => 'template',
                'description' => 'Generate video using template',
                'created_by' => 1,
            ],
            [
                'name' => 'reference2image',
                'description' => 'Generate image from reference',
                'created_by' => 1,
            ],
            [
                'name' => 'text2audio',
                'description' => 'Generate audio from text',
                'created_by' => 1,
            ],
        ];

        foreach ($features as $feature) {
            AiFeature::create($feature);
        }
    }
}