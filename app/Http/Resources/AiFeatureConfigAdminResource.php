<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AiFeatureConfigAdminResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'ai_feature' => [
                'id' => $this->feature->id ?? null,
                'name' => $this->feature->name ?? null,
                'description' => $this->feature->description ?? null,
            ],
            'data_source' => [
                'id' => $this->dataSource->id ?? null,
                'name' => $this->dataSource->name ?? null,
                'url' => $this->dataSource->url ?? null,
            ],

            // Connection details
            'endpoint' => $this->endpoint,
            'status_check_endpoint' => $this->status_check_endpoint,
            'method' => $this->method,
            'content_type' => $this->content_type,
            'body_format' => $this->body_format,

            // Auth details (conditionally shown based on type)
            'auth_type' => $this->auth_type,
            'auth_header_key' => $this->auth_header_key,

            // Static token fields
            'auth_value_from_env' => $this->when(
                in_array($this->auth_type, ['token', 'bearer', 'static_token']),
                $this->auth_value_from_env
            ),

            // JWT fields
            'auth_secret_key_env' => $this->when(
                $this->auth_type === 'jwt',
                $this->auth_secret_key_env
            ),
            'jwt_expiry_seconds' => $this->when(
                $this->auth_type === 'jwt',
                $this->jwt_expiry_seconds
            ),
            'jwt_nbf_offset_seconds' => $this->when(
                $this->auth_type === 'jwt',
                $this->jwt_nbf_offset_seconds
            ),

            // Configuration
            'standard_schema' => $this->standard_schema,
            'response_mapping' => $this->response_mapping,
            'request_body_template' => $this->request_body_template,

            // Meta
            'is_active' => $this->is_active,
            'priority' => $this->priority,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}