<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTemplateRequest extends FormRequest
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
            'name' => 'sometimes|required|string|max:255',

            // ✅ UPDATED: category_ids array instead of single template_category_id
            'category_ids' => 'sometimes|required|array|min:1',
            'category_ids.*' => 'required|integer|exists:template_categories,id',

            'credit_cost' => 'nullable|integer|min:0',
            'ai_feature_id' => 'sometimes|required|integer|exists:ai_features,id',

            // ✅ NEW: data_source_id
            'data_source_id' => 'sometimes|required|integer|exists:data_sources,id',

            // ✅ NEW: Optional fields
            'is_active' => 'nullable|boolean',
            'state' => 'nullable|in:test,live',
            'sequence' => 'nullable|integer|min:0',

            // Template contents
            'contents' => 'nullable|array',
            'contents.*.id' => 'nullable|integer|exists:template_contents,id', // For updating existing
            'contents.*.type' => 'required_with:contents.*.file|in:image,video,cover',
            'contents.*.file' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,webm,ogg|max:102400', // 100MB
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
            'contents.*.id.exists' => 'Content does not exist',
            'contents.*.type.required_with' => 'Content type is required when file is provided',
            'contents.*.type.in' => 'Content type must be image, video, or cover',
            'contents.*.file.max' => 'File size must not exceed 100MB',
        ];
    }
}