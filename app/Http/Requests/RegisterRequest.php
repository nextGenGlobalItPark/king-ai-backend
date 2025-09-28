<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;  // Public registration
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:' . User::NAME_MAX_LENGTH],
            'email' => ['required', 'string', 'email', 'max:' . User::EMAIL_MAX_LENGTH, 'unique:users'],
            'password' => ['required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH, 'confirmed'],
        ];
    }
}