<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'required|integer|exists:template_categories,id',

            'credit_cost' => 'nullable|integer|min:0',
            'ai_feature_id' => 'required|integer|exists:ai_features,id',

            // data_source_id is auto-filled from ai_feature — not required from client
            'data_source_id' => 'nullable|integer|exists:data_sources,id',

            'is_active' => 'nullable|boolean',
            'state' => 'nullable|in:test,live',
            'sequence' => 'nullable|integer|min:0',

            // Metadata — template details for end users
            'metadata' => 'nullable|array',
            'metadata.description' => 'nullable|string|max:1000',
            'metadata.output_type' => 'nullable|in:video,image',
            'metadata.preview_video_content_index' => 'nullable|integer|min:0',
            'metadata.options' => 'nullable|array',
            'metadata.guide' => 'nullable|array',
            'metadata.guide.input_fields' => 'nullable|array',
            'metadata.guide.input_fields.*.name' => 'required_with:metadata.guide.input_fields|string|max:100',
            'metadata.guide.input_fields.*.type' => 'required_with:metadata.guide.input_fields|in:image,text',
            'metadata.guide.input_fields.*.label' => 'required_with:metadata.guide.input_fields|string|max:100',
            'metadata.guide.input_fields.*.required' => 'nullable|boolean',
            'metadata.guide.recommended_image_type' => 'nullable|string|max:255',
            'metadata.guide.notes' => 'nullable|string|max:2000',
            'metadata.guide.limitations' => 'nullable|string|max:2000',

            // Template contents
            'contents' => 'nullable|array',
            'contents.*.type' => 'required_with:contents|in:image,video,cover',
            'contents.*.file' => 'required_with:contents|file|mimes:jpg,jpeg,png,gif,mp4,webm,ogg|max:102400',
            'contents.*.text' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required',
            'category_ids.required' => 'At least one category is required',
            'category_ids.*.exists' => 'Selected category does not exist',
            'ai_feature_id.required' => 'AI feature is required',
            'ai_feature_id.exists' => 'Selected AI feature does not exist',
            'state.in' => 'State must be either test or live',
            'metadata.output_type.in' => 'Output type must be either video or image',
            'metadata.guide.input_fields.*.type.in' => 'Input field type must be either image or text',
            'contents.*.type.required_with' => 'Content type is required when file is provided',
            'contents.*.type.in' => 'Content type must be image, video, or cover',
            'contents.*.file.required_with' => 'File is required for each content item',
            'contents.*.file.max' => 'File size must not exceed 100MB',
        ];
    }
}
