<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            // ✅ UPDATED: category_ids array instead of single template_category_id
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'required|integer|exists:template_categories,id',

            'credit_cost' => 'nullable|integer|min:0',
            'ai_feature_id' => 'required|integer|exists:ai_features,id',

            // ✅ NEW: data_source_id required
            'data_source_id' => 'required|integer|exists:data_sources,id',

            // ✅ NEW: Optional fields
            'is_active' => 'nullable|boolean',
            'state' => 'nullable|in:test,live',
            'sequence' => 'nullable|integer|min:0',

            // Template contents
            'contents' => 'nullable|array',
            'contents.*.type' => 'required_with:contents|in:image,video,cover',
            'contents.*.file' => 'required_with:contents|file|mimes:jpg,jpeg,png,gif,mp4,webm,ogg|max:102400', // 100MB
            'contents.*.text' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required',
            'category_ids.required' => 'At least one category is required',
            'category_ids.*.exists' => 'Selected category does not exist',
            'ai_feature_id.required' => 'AI feature is required',
            'ai_feature_id.exists' => 'Selected AI feature does not exist',
            'data_source_id.required' => 'Data source is required',
            'data_source_id.exists' => 'Selected data source does not exist',
            'state.in' => 'State must be either test or live',
            'contents.*.type.required_with' => 'Content type is required when file is provided',
            'contents.*.type.in' => 'Content type must be image, video, or cover',
            'contents.*.file.required_with' => 'File is required for each content item',
            'contents.*.file.max' => 'File size must not exceed 100MB',
        ];
    }
}