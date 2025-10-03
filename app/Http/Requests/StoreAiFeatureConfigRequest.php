<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiFeatureConfigRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'ai_feature_id' => 'required|exists:ai_features,id',
            'data_source_id' => 'required|integer|exists:data_sources,id',
            'endpoint' => 'required|url|max:255',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'auth_type' => 'nullable|string|in:token,bearer',
            'auth_header_key' => 'nullable|string|max:50',
            'auth_value_from_env' => 'required|string|max:50',
            'content_type' => 'nullable|string|max:50',
            'body_format' => 'nullable|in:json,form-data,urlencoded',
            'param_mapping' => 'required|json',
            'standard_schema' => 'required|json',
            'default_params' => 'nullable|json',
            'extra_params' => 'nullable|json',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0|max:100',
        ];
    }
}
