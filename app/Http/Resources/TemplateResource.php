<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'template_category' => $this->templateCategory ? [
                'id' => $this->templateCategory->id,
                'template_category_name' => $this->templateCategory->template_category_name,
                'template_category_slug' => $this->templateCategory->template_category_slug,
            ] : null,
            'credit_cost' => $this->credit_cost,
            'created_by' => $this->created_by,
            'template_apis' => $this->templateApis->map(function ($api) {
                return [
                    'data_source_id' => $api->data_source_id,
                    'data_source_name' => $api->dataSource ? $api->dataSource->name : null,
                    'model' => $api->model,
                    'style' => $api->style,
                    'images' => $api->images,
                    'prompt' => $api->prompt,
                    'duration' => $api->duration,
                    'seed' => $api->seed,
                    'aspect_ratio' => $api->aspect_ratio,
                    'bgm' => $api->bgm,
                    'resolution' => $api->resolution,
                    'movement_amplitude' => $api->movement_amplitude,
                    'off_peak' => $api->off_peak,
                    'payload' => $api->payload,
                    'request_url' => $api->request_url,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}