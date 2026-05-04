<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/users (admin-only).
 *
 * Admins no longer supply a password — the controller calls
 * UserService::createUserWithTemporaryPassword(), which generates one,
 * emails the user, and returns it once in the API response so the admin
 * can also share it out-of-band. The user is forced to change it on
 * first login.
 *
 * The 'role' field is optional and defaults to 'user' in the controller
 * if omitted. Admins can pass 'admin' here to provision another admin
 * user. The route is already gated by manage-users so we don't need
 * extra authorization for the role choice — only admins reach this
 * request in the first place.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) Auth::user();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:' . User::NAME_MAX_LENGTH],
            'email' => ['required', 'string', 'email', 'max:' . User::EMAIL_MAX_LENGTH, 'unique:users'],
            'role' => ['nullable', 'string', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
        ];
    }
}
