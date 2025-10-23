<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $templateContents = collect($this->templates ?? [])->map(function ($template) {
            $contents = collect($template->templateContents ?? [])->values();

            return [
                'id' => $template->id,
                'name' => $template->name,
                'credit_cost' => $template->credit_cost,
                'is_active' => $template->is_active,
                'state' => $template->state,
                'sequence' => $template->sequence,
                'created_by' => optional($template->createdBy)->only(['id', 'name']),
                'ai_feature' => optional($template->aiFeature, function ($aiFeature) {
                    return [
                        'id' => $aiFeature->id,
                        'name' => $aiFeature->name,
                        'configs' => collect($aiFeature->configs ?? [])->map(function ($config) {
                            return [
                                'data_source' => optional($config->dataSource)->only(['id', 'name']),
                                'standard_schema' => $config->standard_schema,
                                'endpoint' => $config->endpoint,
                                'method' => $config->method,
                                'auth_type' => $config->auth_type,
                                'auth_header_key' => $config->auth_header_key,
                                'auth_value_from_env' => $config->auth_value_from_env,
                                'content_type' => $config->content_type,
                                'body_format' => $config->body_format,
                                'param_mapping' => $config->param_mapping,
                                'default_params' => $config->default_params,
                                'extra_params' => $config->extra_params,
                                'is_active' => $config->is_active,
                                'priority' => $config->priority,
                            ];
                        })->values()->toArray(),
                    ];
                }),
                'template_contents' => $contents->map(function ($content) {
                    return [
                        'id' => $content->id,
                        'template_content_type' => $content->template_content_type,
                        'template_content' => $content->template_content,
                        'text' => $content->text,
                        'created_by' => $content->created_by,
                        'created_at' => $content->created_at,
                        'updated_at' => $content->updated_at,
                    ];
                })->toArray(),
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ];
        })->values()->toArray();

        return [
            'id' => $this->id,
            'template_category_name' => $this->template_category_name,
            'template_category_slug' => $this->template_category_slug,
            'is_active' => $this->is_active,
            'state' => $this->state,
            'sequence' => $this->sequence,
            'data_sources' => collect($this->dataSources ?? [])->map(function ($dataSource) {
                return [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                    'url' => $dataSource->url,
                ];
            })->values()->toArray(),
            'template' => $templateContents,
            'created_by' => ($this->createdBy)->only(['id', 'name']),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}