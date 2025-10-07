<?php

namespace Database\Seeders;

use App\Models\AiFeatureConfig;
use App\Models\AiFeature;
use Illuminate\Database\Seeder;

class AiFeatureConfigsSeeder extends Seeder
{
    public function run(): void
    {
        $viduText2Video = AiFeature::where('name', 'text2video')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduText2Video,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/text2video',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Token',
            'param_mapping' => json_encode([
                'prompt' => 'prompt',
                'duration' => 'duration',
                'aspect_ratio' => 'aspect_ratio',
                'resolution' => 'resolution'
            ]),
            'default_params' => json_encode([
                'model' => 'viduq1',
                'style' => 'general',
                'seed' => 0,
                'movement_amplitude' => 'auto',
                'off_peak' => false
            ]),
            'created_by' => 1,
        ]);

        $klingText2Video = AiFeature::where('name', 'text2video')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $klingText2Video,
            'data_source_id' => 2,
            'endpoint' => 'https://api.useapi.net/v1/kling/videos/text2video',
            'auth_value_from_env' => 'KLING_API_KEY',  // Assume bearer or custom
            'auth_header_key' => 'Authorization',
            'content_type' => 'multipart/form-data',
            'body_format' => 'form-data',
            'param_mapping' => json_encode([
                'prompt' => 'prompt',
                'duration' => 'duration',
                'aspect_ratio' => 'aspect_ratio'
            ]),
            'default_params' => json_encode([
                'negative_prompt' => '',
                'cfg_scale' => '',
                'mode' => '',
                'model_name' => '',
                'enable_audio' => '',
                'maxJobs' => '',
                'replyRef' => '',
                'replyUrl' => ''
            ]),
            'created_by' => 1,
        ]);

        // Add similar for other features/vendors...
    }
}