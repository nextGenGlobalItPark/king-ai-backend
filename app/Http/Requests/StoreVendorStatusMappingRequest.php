<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorStatusMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'data_source_id' => 'required|integer|exists:data_sources,id|unique:vendor_status_mappings,data_source_id',
            'status_mapping' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    // Must have keys: processing, completed, failed
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
            'status_mapping.completed' => 'required',  // Can be string or array
            'status_mapping.failed' => 'required|array|min:1',
            'status_mapping.failed.*' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'data_source_id.unique' => 'A status mapping already exists for this vendor. Use update endpoint to modify it.',
            'status_mapping.required' => 'Status mapping configuration is required.',
            'status_mapping.processing.min' => 'At least one processing status must be defined.',
            'status_mapping.failed.min' => 'At least one failed status must be defined.',
        ];
    }
}