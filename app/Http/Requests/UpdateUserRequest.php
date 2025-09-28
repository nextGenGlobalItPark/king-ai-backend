<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        if(Auth::user())
            return true;
        return false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:' . User::NAME_MAX_LENGTH],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:' . User::EMAIL_MAX_LENGTH, Rule::unique('users')->ignore($this->user)],
            'password' => ['sometimes', 'required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH, 'confirmed'],
        ];
    }
}