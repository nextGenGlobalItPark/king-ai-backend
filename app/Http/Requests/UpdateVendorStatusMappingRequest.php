<?php
// app/Http/Requests/UpdateVendorStatusMappingRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorStatusMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'status_mapping' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    $requiredKeys = ['processing', 'completed', 'failed'];
                    foreach ($requiredKeys as $key) {
                        if (!isset($value[$key])) {
                            $fail("The status_mapping must include '{$key}' key.");
                        }
                    }
                }
            ],
            'status_mapping.processing' => 'required|array|min:1',
            'status_mapping.processing.*' => 'required|string',
            'status_mapping.completed' => 'required',
            'status_mapping.failed' => 'required|array|min:1',
            'status_mapping.failed.*' => 'required|string',
        ];
    }
}