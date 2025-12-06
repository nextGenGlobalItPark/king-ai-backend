<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJwtConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Note: ai_feature_id and data_source_id cannot be changed after creation

            // API Configuration
            'endpoint' => 'sometimes|url|max:500',
            'method' => 'sometimes|in:GET,POST,PUT,PATCH,DELETE',
            'content_type' => 'sometimes|string|max:100',
            'body_format' => 'sometimes|in:json,form-data,form_params',

            // JWT Authentication
            'auth_header_key' => 'sometimes|string|max:100',
            'auth_value_from_env' => 'sometimes|string|max:100', // Access Key env var
            'auth_secret_key_env' => 'sometimes|string|max:100', // Secret Key env var
            'jwt_expiry_seconds' => 'nullable|integer|min:60|max:86400',
            'jwt_nbf_offset_seconds' => 'nullable|integer|min:0|max:300',

            // NEW: Request Body Template (recommended)
            'standard_schema' => 'sometimes|array',
            'request_body_template' => 'nullable|array',

            // Response parsing
            'response_mapping' => 'sometimes|array',

            // Metadata
            'is_active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0',
        ];
    }

    /**
     * Get custom validation messages
     */
    public function messages(): array
    {
        return [
            'endpoint.url' => 'API endpoint must be a valid URL',
            'standard_schema.array' => 'Standard schema must be valid JSON',
            'request_body_template.array' => 'Request body template must be valid JSON',
            'response_mapping.array' => 'Response mapping must be valid JSON',
        ];
    }

    /**
     * Validate standard_schema structure
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('request_body_template') && $this->has('standard_schema')) {
                $template = $this->request_body_template;
                $schema = $this->standard_schema;

                $schemaFieldNames = array_column($schema['fields'] ?? [], 'name');

                $templateJson = json_encode($template);
                preg_match_all('/{{([^}:]+)/', $templateJson, $matches);
                $placeholders = array_unique($matches[1] ?? []);

                foreach ($placeholders as $placeholder) {
                    if (!in_array($placeholder, $schemaFieldNames)) {
                        $validator->errors()->add(
                            'request_body_template',
                            "Template uses undefined field: '{$placeholder}'. All placeholders must be defined in standard_schema."
                        );
                    }
                }
            }
        });
    }
}