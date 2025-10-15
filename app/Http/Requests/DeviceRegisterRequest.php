<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeviceRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string|max:255',
            'type' => 'required|in:fcm,apns,onesignal',
            'platform' => 'required|in:android,ios',
        ];
    }
}