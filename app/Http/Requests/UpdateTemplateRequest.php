<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'credit_cost' => 'nullable|integer|min:0',
            'ai_feature_id' => 'sometimes|required|exists:ai_features,id',
            'contents' => 'sometimes|array',
            'contents.*.id' => 'nullable|exists:template_contents,id',
            'contents.*.type' => 'required_with:contents.*.file|in:image,video,cover',
            'contents.*.file' => [
                'nullable',
                'file',
                Rule::when($this->input('contents.*.type') === 'image', 'mimes:jpeg,jpg,png,gif|max:5120'),
                Rule::when($this->input('contents.*.type') === 'video', 'mimes:mp4,mov,avi,wmv|max:102400'),
                Rule::when($this->input('contents.*.type') === 'cover', 'mimes:jpeg,jpg,png,gif|max:5120'),
            ],
            'contents.*.text' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'contents.*.file.mimes' => 'Invalid file type for :attribute.',
            'contents.*.file.max' => 'File size too large for :attribute.',
        ];
    }
}