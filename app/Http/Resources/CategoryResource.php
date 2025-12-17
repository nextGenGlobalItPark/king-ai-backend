<?php

namespace App\Http\Resources;

use App\Models\VendorStatusMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_category_name' => $this->template_category_name,
            'template_category_slug' => $this->template_category_slug,
            'is_active' => $this->is_active,
            'state' => $this->state,
            'sequence' => $this->sequence,
            'template' => $this->whenLoaded('templates', function () {
                return $this->templates->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'credit_cost' => $template->credit_cost,
                        'is_active' => $template->is_active,
                        'state' => $template->state,
                        'sequence' => $template->sequence,
                        'ai_feature' => $this->transformAiFeature($template),
                        'template_contents' => $this->transformTemplateContents($template),
                        'data_source' => [
                            'id' => $template->dataSource->id,
                            'name' => $template->dataSource->name,
                            'url' => $template->dataSource->url,
                        ],
                    ];
                });
            }),
        ];
    }

    /**
     * Transform AI Feature data
     */
    private function transformAiFeature($template)
    {
        if (!$template->relationLoaded('aiFeature')) {
            return null;
        }

        $aiFeature = $template->aiFeature;

        return [
            'id' => $aiFeature->id,
            'name' => $aiFeature->name,
            'configs' => $aiFeature->relationLoaded('configs')
                ? $this->transformConfigs($aiFeature->configs)
                : [],
        ];
    }

    /**
     * Transform AI Feature Configs
     */
    private function transformConfigs($configs)
    {
        return $configs->map(function ($config) {
            $configData = [
                'id' => $config->id,
                'data_source' => [
                    'id' => $config->dataSource->id,
                    'name' => $config->dataSource->name,
                ],
                'endpoint' => $config->endpoint,
                'status_check_endpoint' => $config->status_check_endpoint,
                'method' => $config->method,
                'auth_type' => $config->auth_type,
                'auth_header_key' => $config->auth_header_key,
                'auth_value_from_env' => $config->auth_value_from_env,
                'content_type' => $config->content_type,
                'body_format' => $config->body_format,
                'is_active' => $config->is_active,
                'priority' => $config->priority,
            ];

            // Include standard_schema and request_body_template
            $configData['standard_schema'] = $config->standard_schema;
            $configData['request_body_template'] = $config->request_body_template;

            // Always include response_mapping
            $configData['response_mapping'] = $config->response_mapping;

            // Include JWT fields if applicable
            if ($config->auth_type === 'jwt') {
                $configData['auth_secret_key_env'] = $config->auth_secret_key_env;
                $configData['jwt_expiry_seconds'] = $config->jwt_expiry_seconds;
                $configData['jwt_nbf_offset_seconds'] = $config->jwt_nbf_offset_seconds;
            }

            return $configData;
        });
    }

    /**
     * Transform template contents
     */
    private function transformTemplateContents($template)
    {
        if (!$template->relationLoaded('templateContents')) {
            return [];
        }

        return $template->templateContents->map(function ($content) {
            return [
                'id' => $content->id,
                'template_content_type' => $content->template_content_type,
                'template_content' => $content->template_content,
                'text' => $content->text,
                'created_at' => $content->created_at,
                'updated_at' => $content->updated_at,
            ];
        });
    }

    /**
     * Get vendor status mapping for a data source
     */
    private function getVendorStatusMapping($dataSourceId)
    {
        $mapping = VendorStatusMapping::where('data_source_id', $dataSourceId)->first();

        if (!$mapping) {
            return null;
        }

        return [
            'id' => $mapping->id,
            'status_mapping' => $mapping->status_mapping,
        ];
    }
}