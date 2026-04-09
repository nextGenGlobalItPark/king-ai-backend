<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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

            // Metadata — user-facing template details
            'metadata' => $this->whenNotNull($this->metadata, function () {
                return $this->resolveMetadata($this->metadata);
            }),

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

            // AI Feature with its configs
            'ai_feature' => $this->whenLoaded('aiFeature', function () {
                $configs = $this->aiFeature->relationLoaded('configs')
                    ? $this->transformConfigs($this->aiFeature->configs)
                    : [];

                return [
                    'id' => $this->aiFeature->id,
                    'name' => $this->aiFeature->name,
                    // Data source comes from the AI Feature (active vendor selection)
                    'data_source' => $this->aiFeature->dataSource ? [
                        'id' => $this->aiFeature->dataSource->id,
                        'name' => $this->aiFeature->dataSource->name,
                        'url' => $this->aiFeature->dataSource->url,
                    ] : null,
                    'configs' => $configs,
                ];
            }),

            // Template Contents — with full URLs
            'template_contents' => $this->whenLoaded('templateContents', function () {
                return $this->templateContents->map(function ($content) {
                    return [
                        'id' => $content->id,
                        'type' => $content->template_content_type,
                        'url' => self::buildContentUrl($content->template_content),
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
     * Resolve metadata — convert sample_content_index references to actual URLs.
     *
     * The admin stores sample_content_index in input fields which points to
     * a template_contents entry. The API resolves this to the actual URL
     * so the mobile app gets ready-to-use image URLs.
     */
    private function resolveMetadata(?array $metadata): ?array
    {
        if (empty($metadata)) {
            return null;
        }

        $resolved = $metadata;

        // Resolve preview_video_content_index to URL
        if (isset($resolved['preview_video_content_index']) && $this->relationLoaded('templateContents')) {
            $index = $resolved['preview_video_content_index'];
            $contents = $this->templateContents->values();
            if (isset($contents[$index])) {
                $resolved['preview_video_url'] = self::buildContentUrl($contents[$index]->template_content);
            }
            unset($resolved['preview_video_content_index']);
        }

        // Resolve sample_content_index in input fields to URLs
        if (!empty($resolved['guide']['input_fields']) && $this->relationLoaded('templateContents')) {
            $contents = $this->templateContents->values();

            foreach ($resolved['guide']['input_fields'] as &$field) {
                if (isset($field['sample_content_index'])) {
                    $index = $field['sample_content_index'];
                    if (isset($contents[$index])) {
                        $field['sample_image_url'] = self::buildContentUrl($contents[$index]->template_content);
                    }
                    unset($field['sample_content_index']);
                }
            }
            unset($field);
        }

        return $resolved;
    }

    /**
     * Build a full URL from a storage path.
     */
    public static function buildContentUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Transform AI Feature Configs — only non-sensitive fields for the client
     */
    private function transformConfigs($configs)
    {
        return $configs->map(function ($config) {
            return [
                'id' => $config->id,
                'data_source' => $config->dataSource ? [
                    'id' => $config->dataSource->id,
                    'name' => $config->dataSource->name,
                ] : null,
                'endpoint' => $config->endpoint,
                'status_check_endpoint' => $config->status_check_endpoint,
                'method' => $config->method,
                'auth_type' => $config->auth_type,
                'content_type' => $config->content_type,
                'body_format' => $config->body_format,
                'is_active' => $config->is_active,
                'priority' => $config->priority,
                'standard_schema' => $config->standard_schema,
                'request_body_template' => $config->request_body_template,
                'response_mapping' => $config->response_mapping,
            ];
        });
    }
}
