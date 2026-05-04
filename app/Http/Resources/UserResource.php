<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Includes roles for client convenience — mobile/web apps can
     * gate their own UI using these. Permissions are NOT included
     * to keep the response small; clients should rely on roles
     * for UI gating and let the server enforce permissions on
     * each endpoint call.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all(), $this->getRoleNames()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
