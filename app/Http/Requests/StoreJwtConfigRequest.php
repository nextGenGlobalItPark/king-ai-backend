<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJwtConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'ai_feature_id' => 'required|exists:ai_features,id',
            'data_source_id' => 'required|exists:data_sources,id',

            'endpoint' => 'required|url|max:500',
            'status_check_endpoint' => 'required|url|max:500',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'content_type' => 'required|string|max:100',
            'body_format' => 'required|in:json,form-data,form_params',

            'auth_header_key' => 'required|string|max:100',
            'auth_value_from_env' => 'required|string|max:100',
            'auth_secret_key_env' => 'required|string|max:100',
            'jwt_expiry_seconds' => 'nullable|integer|min:60|max:86400',
            'jwt_nbf_offset_seconds' => 'nullable|integer|min:0|max:300',

            'standard_schema' => 'required|array',
            'standard_schema.fields' => 'required|array',
            'standard_schema.fields.*.name' => 'required|string',
            'standard_schema.fields.*.type' => 'required|string',
            'request_body_template' => 'nullable|array',

            'response_mapping' => 'required|array',

            'is_active' => 'boolean',
            'priority' => 'integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'ai_feature_id.required' => 'AI feature is required',
            'ai_feature_id.exists' => 'Selected AI feature does not exist',
            'data_source_id.required' => 'Data source is required',
            'data_source_id.exists' => 'Selected data source does not exist',
            'endpoint.required' => 'API endpoint is required',
            'status_check_endpoint.required' => 'API for status check endpoint is required',
            'endpoint.url' => 'API endpoint must be a valid URL',
            'auth_value_from_env.required' => 'JWT access key environment variable is required',
            'auth_secret_key_env.required' => 'JWT secret key environment variable is required',
            'standard_schema.required' => 'Standard schema is required',
            'standard_schema.array' => 'Standard schema must be a valid object/array',
            'standard_schema.fields.required' => 'Standard schema must have a "fields" array',
            'standard_schema.fields.*.name.required' => 'Each field must have a "name" property',
            'standard_schema.fields.*.type.required' => 'Each field must have a "type" property',
            'request_body_template.array' => 'Request body template must be a valid object/array',
            'response_mapping.required' => 'Response mapping is required',
            'response_mapping.array' => 'Response mapping must be a valid object/array',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->is_active ?? true,
            'priority' => $this->priority ?? 1,
            'method' => $this->method ?? 'POST',
            'content_type' => $this->content_type ?? 'application/json',
            'body_format' => $this->body_format ?? 'json',
            'auth_header_key' => $this->auth_header_key ?? 'Authorization',
            'jwt_expiry_seconds' => $this->jwt_expiry_seconds ?? 1800,
            'jwt_nbf_offset_seconds' => $this->jwt_nbf_offset_seconds ?? 5,
        ]);
    }

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