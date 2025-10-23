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
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        'max' => 1000,
                        'name' => 'prompt',
                        'type' => 'string',
                        'label' => 'Custom Prompt',
                        'ui_hint' => 'Keep under 1000 characters for best results.',
                        'required' => true
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'model' => 'viduq1',
                'style' => 'general',
                'seed' => 0,
                'movement_amplitude' => 'auto',
                'off_peak' => false
            ]),
            'param_mapping' => json_encode([
                "prompt" => "prompt"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduImg2Video = AiFeature::where('name', 'img2video')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduImg2Video,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/img2video',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        'max' => 1000,
                        'name' => 'prompt',
                        'type' => 'string',
                        'label' => 'Custom Prompt',
                        'ui_hint' => 'Keep under 1000 characters for best results.',
                        'required' => true
                    ],
                    [
                        "max" => 1,
                        "min" => 1,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 1 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'model' => 'viduq1',
                'style' => 'general',
                'seed' => 0,
                'movement_amplitude' => 'auto',
                'off_peak' => false
            ]),
            'param_mapping' => json_encode([
                "prompt" => "prompt",
                "images" => "images",
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduKissingEverywhere8s = AiFeature::where('name', 'kissing_everywhere_8s')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduKissingEverywhere8s,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/template',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        "max" => 3,
                        "min" => 3,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 3 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'prompt' => '图一和图二中的人物在图三场景中热烈地亲吻',
                'template' => "kissing_everywhere_8s",
                'aspect_ratio' => '16:9',
                'seed' => 0,
                'bgm' => true
            ]),
            'param_mapping' => json_encode([
                "images" => "images"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduFridgeMagnet = AiFeature::where('name', 'fridge_magnet')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduFridgeMagnet,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/template',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        "max" => 1,
                        "min" => 1,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 1 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'template' => 'fridge_magnet',
                'aspect_ratio' => 'auto',
                'seed' => 0,
            ]),
            'param_mapping' => json_encode([
                "images" => "images"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduZoomIn = AiFeature::where('name', 'zoom_in')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduZoomIn,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/template',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        "max" => 1,
                        "min" => 1,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 1 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'template' => 'zoom_in',
                'prompt' => 'Video content\n The product remains completely stationary while the camera zooms in to showcase it.\nRequirement:\n1. Camera Movement: Zoom in',
                'seed' => 0,
            ]),
            'param_mapping' => json_encode([
                "images" => "images"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduAutoSpin = AiFeature::where('name', 'auto_spin')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduAutoSpin,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/template',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        "max" => 1,
                        "min" => 1,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 1 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'template' => 'auto_spin',
                'prompt' => "The subject in the photo slowly rotates counterclockwise at a steady and fluid pace, with even lighting illuminating the surface. The camera remains fixed, highlighting the dynamic beauty of the rotation.\n# Requirement\n- Camera Movement must be 'Static Shot'",
                'seed' => 0,
            ]),
            'param_mapping' => json_encode([
                "images" => "images"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);

        $viduLivePhoto = AiFeature::where('name', 'live_photo')->first()->id;
        AiFeatureConfig::create([
            'ai_feature_id' => $viduLivePhoto,
            'data_source_id' => 1,
            'endpoint' => 'https://api.vidu.com/ent/v2/template',
            'auth_type' => 'Token',
            'auth_value_from_env' => 'VIDU_API_KEY',
            'auth_header_key' => 'Authorization',
            'standard_schema' => json_encode([
                'fields' => [
                    [
                        "max" => 1,
                        "min" => 1,
                        "each" => "string",
                        "name" => "images",
                        "type" => "array",
                        "label" => "Input Images",
                        "ui_hint" => "Upload exactly 1 images to start the emotion sequence.",
                        "required" => true,
                    ]
                ]
            ]),
            'default_params' => json_encode([
                'template' => 'live_photo',
                'prompt' => 'The camera slowly zooms in, while the wind gently blows on the subject.',
                'seed' => 0,
            ]),
            'param_mapping' => json_encode([
                "images" => "images"
            ]),
            'created_by' => 1,
            'is_active' => 1,
            'priority' => 1
        ]);


    }
}