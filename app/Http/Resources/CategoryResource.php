<?php

namespace App\Http\Resources;

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
            'data_sources' => $this->dataSources->map(function ($dataSource) {
                return [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                    'url' => $dataSource->url,
                    'pivot' => [
                        'source_db_id' => $dataSource->pivot->source_db_id,
                        'created_by' => $dataSource->pivot->created_by,
                    ],
                ];
            }),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
