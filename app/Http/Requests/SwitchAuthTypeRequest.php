<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwitchAuthTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'new_auth_type' => 'required|in:static_token,token,bearer,jwt',
            'confirm' => 'required|boolean|accepted', // Force explicit confirmation
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.accepted' => 'You must confirm the auth type switch. This will clear incompatible fields.',
        ];
    }
}