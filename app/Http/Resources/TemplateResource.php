<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
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
            'name' => $this->name,
            'credit_cost' => $this->credit_cost,
            'is_active' => $this->is_active,
            'state' => $this->state,
            'sequence' => $this->sequence,

            // Categories - many-to-many relationship
            'categories' => $this->whenLoaded('templateCategories', function () {
                return $this->templateCategories->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'template_category_name' => $category->template_category_name,
                        'template_category_slug' => $category->template_category_slug,
                        'is_active' => $category->is_active,
                        'state' => $category->state,
                        'sequence' => $category->sequence,
                    ];
                });
            }),

            // AI Feature
            'ai_feature' => $this->whenLoaded('aiFeature', function () {
                $configs = $this->aiFeature->relationLoaded('configs')
                    ? $this->transformConfigs($this->aiFeature->configs)
                    : [];

                return [
                    'id' => $this->aiFeature->id,
                    'name' => $this->aiFeature->name,
                    'configs' => $configs,
                ];
            }),

            // Data Source
            'data_source' => $this->whenLoaded('dataSource', function () {
                return [
                    'id' => $this->dataSource->id,
                    'name' => $this->dataSource->name,
                    'url' => $this->dataSource->url,
                    'vendor_status_mapping' => $this->whenLoaded('dataSource', function () {
                        // Use eager-loaded relation if available, avoids N+1
                        if ($this->dataSource->relationLoaded('vendorStatusMapping') && $this->dataSource->vendorStatusMapping) {
                            $mapping = $this->dataSource->vendorStatusMapping;
                            return [
                                'id' => $mapping->id,
                                'status_mapping' => $mapping->status_mapping,
                                'created_at' => $mapping->created_at,
                                'updated_at' => $mapping->updated_at,
                            ];
                        }
                        return null;
                    }),
                ];
            }),

            // Template Contents
            'template_contents' => $this->whenLoaded('templateContents', function () {
                return $this->templateContents->map(function ($content) {
                    return [
                        'id' => $content->id,
                        'template_content_type' => $content->template_content_type,
                        'template_content' => $content->template_content,
                        'text' => $content->text,
                        'created_at' => $content->created_at,
                        'updated_at' => $content->updated_at,
                    ];
                });
            }),

            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
            if (!empty($config->request_body_template)) {
                $configData['standard_schema'] = $config->standard_schema;
                $configData['request_body_template'] = $config->request_body_template;
            } else {
                $configData['standard_schema'] = $config->standard_schema;
            }

            // Always include response_mapping
            if (!empty($config->response_mapping)) {
                $configData['response_mapping'] = $config->response_mapping;
            }

            // Include JWT fields if applicable
            if ($config->auth_type === 'jwt') {
                $configData['auth_secret_key_env'] = $config->auth_secret_key_env;
                $configData['jwt_expiry_seconds'] = $config->jwt_expiry_seconds;
                $configData['jwt_nbf_offset_seconds'] = $config->jwt_nbf_offset_seconds;
            }

            return $configData;
        });
    }
}