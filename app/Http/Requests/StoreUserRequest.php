<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if(Auth::user())
            return true;
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:' . User::NAME_MAX_LENGTH],
            'email' => ['required', 'string', 'email', 'max:' . User::EMAIL_MAX_LENGTH, 'unique:users'],
            'password' => ['required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH, 'confirmed'],
        ];
    }
}
