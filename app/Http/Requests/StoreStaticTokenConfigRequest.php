<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaticTokenConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check(); // All authenticated users (admins)
    }

    public function rules(): array
    {
        return [
            'ai_feature_id' => 'required|exists:ai_features,id',
            'data_source_id' => 'required|integer|exists:data_sources,id',
            'endpoint' => 'required|url|max:255',
            'status_check_endpoint' => 'required|url|max:255',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'auth_type' => 'required|in:token,bearer,static_token',
            'auth_header_key' => 'required|string|max:50',
            'auth_value_from_env' => 'required|string|max:100',

            'content_type' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9\-\/]+$/',
            'body_format' => 'nullable|in:json,form-data,urlencoded',
            'standard_schema' => 'required|json',
            'request_body_template' => 'nullable|json',
            'response_mapping' => 'nullable|array',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0|max:100',
        ];
    }

    /**
     * Get custom validation messages
     */
    public function messages(): array
    {
        return [
            'ai_feature_id.required' => 'AI feature is required',
            'ai_feature_id.exists' => 'Selected AI feature does not exist',
            'data_source_id.required' => 'Data source is required',
            'data_source_id.exists' => 'Selected data source does not exist',
            'endpoint.required' => 'API endpoint is required',
            'status_check_endpoint.required' => 'API for status check is required',
            'endpoint.url' => 'API endpoint must be a valid URL',
            'standard_schema.required' => 'Standard schema is required',
            'standard_schema.json' => 'Standard schema must be valid JSON',
            'request_body_template.json' => 'Request body template must be valid JSON',
            'response_mapping.required' => 'Response mapping is required',
            'response_mapping.json' => 'Response mapping must be valid JSON',
        ];
    }

    /**
     * Prepare the data for validation
     */
    protected function prepareForValidation(): void
    {
        // Set default values if not provided
        $this->merge([
            'is_active' => $this->is_active ?? true,
            'priority' => $this->priority ?? 1,
            'method' => $this->method ?? 'POST',
            'content_type' => $this->content_type ?? 'application/json',
            'body_format' => $this->body_format ?? 'json',
            'auth_header_key' => $this->auth_header_key ?? 'Authorization',
        ]);
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