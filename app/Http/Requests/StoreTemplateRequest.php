<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();  // Assuming authenticated users can create templates
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'credit_cost' => 'nullable|integer|min:0',
            'ai_feature_id' => 'required|exists:ai_features,id',
            'contents' => 'required|array|min:1',
            'contents.*.type' => 'required|in:image,video,cover',
            'contents.*.file' => [
                'required',
                'file',
                Rule::when($this->input('contents.*.type') === 'image', 'mimes:jpeg,jpg,png,gif|max:5120'),  // 5MB
                Rule::when($this->input('contents.*.type') === 'video', 'mimes:mp4,mov,avi,wmv|max:102400'),  // 100MB
                Rule::when($this->input('contents.*.type') === 'cover', 'mimes:jpeg,jpg,png,gif|max:5120'),
            ],
            'contents.*.text' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'contents.*.file.required' => 'A file is required for each content.',
            'contents.*.file.mimes' => 'Invalid file type for :attribute.',
            'contents.*.file.max' => 'File size too large for :attribute.',
        ];
    }
}