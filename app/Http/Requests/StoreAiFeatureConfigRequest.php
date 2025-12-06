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
            'auth_type' => 'required|in:token,bearer,static_token,jwt',
            'auth_header_key' => 'nullable|string|max:50',
            'auth_value_from_env' => 'required|string|max:50',
            'auth_secret_key_env' => 'required_if:auth_type,jwt|string|max:50',
            'jwt_expiry_seconds' => 'nullable|integer|min:60|max:86400', // 1min to 24hrs
            'jwt_nbf_offset_seconds' => 'nullable|integer|min:0|max:300', // 0 to 5min
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
