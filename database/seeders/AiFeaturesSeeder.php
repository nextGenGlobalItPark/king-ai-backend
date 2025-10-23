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
                'name' => 'kissing_everywhere_8s',
                'description' => 'kissing_everywhere_8s',
                'created_by' => 1,
            ],
            [
                'name' => 'fridge_magnet',
                'description' => 'fridge_magnet',
                'created_by' => 1,
            ],
            [
                'name' => 'zoom_in',
                'description' => 'zoom_in',
                'created_by' => 1,
            ],
            [
                'name' => 'auto_spin',
                'description' => 'auto_spin',
                'created_by' => 1,
            ],
            [
                'name' => 'live_photo',
                'description' => 'live_photo',
                'created_by' => 1,
            ]
        ];

        foreach ($features as $feature) {
            AiFeature::create($feature);
        }
    }
}