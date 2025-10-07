<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            'data_sources' => $this->dataSources->map(function ($dataSource) {
                return [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                    'url' => $dataSource->url
                ];
            }),
            'template' => $this->templates->map(function ($template) {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'credit_cost' => $template->credit_cost,
                    'created_by' => [
                        'id' => $template->createdBy->id,
                        'name' => $template->createdBy->name,
                    ],
                    'ai_feature' => [
                        'id' => $template->aiFeature->id,
                        'name' => $template->aiFeature->name,
                        'configs' => $template->aiFeature->configs->map(function ($config) {
                            return [
                                'data_source' => [
                                    'id' => $config->dataSource->id,
                                    'name' => Str::lower($config->dataSource->name),
                                ],
                                'standard_schema' => $config->standard_schema,
                            ];
                        }),
                    ],
                    'template_contents' => $template->templateContents->map(function ($content) {
                        return [
                            'id' => $content->id,
                            'template_content_type' => $content->template_content_type,
                            'template_content' => $content->template_content,
                            'text' => $content->text,
                            'created_by' => $content->created_by,
                            'created_at' => $content->created_at,
                            'updated_at' => $content->updated_at,
                        ];
                    }),
                    'created_at' => $template->created_at,
                    'updated_at' => $template->updated_at,
                ];
            }),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
