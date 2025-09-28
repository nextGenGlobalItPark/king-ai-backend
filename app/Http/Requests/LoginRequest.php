<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public route, no authentication required
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:' . User::EMAIL_MAX_LENGTH],
            'password' => ['required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH],
        ];
    }
}