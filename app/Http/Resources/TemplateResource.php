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
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,  // Assuming User has 'name'
            ] : null,
            'ai_feature' => $this->aiFeature ? [
                'id' => $this->aiFeature->id,
                'name' => $this->aiFeature->name,
            ] : null,
            'template_contents' => $this->whenLoaded('templateContents', function () {
                return $this->templateContents->map(function ($content) {
                    return [
                        'id' => $content->id,
                        'template_content_type' => $content->template_content_type,
                        'template_content' => $content->template_content,
                        'text' => $content->text,
                        'created_by' => $content->created_by,
                        'created_at' => $content->created_at,
                        'updated_at' => $content->updated_at,
                    ];
                });
            }, collect()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}