<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ViduTemplateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'template_id' => $this->template_id,
            'resource_id' => $this->resource_id,
            'name' => $this->name,
            'resolution' => $this->resolution,
            'duration' => $this->duration,
            'aspect_ratio' => $this->aspect_ratio,
            'credit_cost' => $this->credit_cost,
            'scene' => $this->scene,
            'template_category' => $this->templateCategory ? [
                'id' => $this->templateCategory->id,
                'template_category_name' => $this->templateCategory->template_category_name,
                'template_category_slug' => $this->templateCategory->template_category_slug,
            ] : null,
            'cover_url' => $this->cover_url,
            'type' => $this->type,
            'video_url' => $this->video_url,
            'image_count' => $this->image_count,
            'image_url' => $this->image_url,
            'person_count' => $this->person_count,
            'attention' => $this->attention,
            'prompt_instruction' => $this->prompt_instruction,
            'effect_boundary' => $this->effect_boundary,
            'request_body' => $this->request_body,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'template' => $this->template ? [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'credit_cost' => $this->template->credit_cost,
                'template_contents' => $this->template->templateContents->map(function ($content) {
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
            ] : null,
        ];
    }
}