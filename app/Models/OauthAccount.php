<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a linked OAuth identity for a user.
 *
 * One User can have multiple OauthAccounts (one per provider), but each
 * (provider, provider_id) pair is unique across the system — two users
 * cannot share the same Google or Apple identity.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $provider        'google' | 'apple'
 * @property string $provider_id     Supabase user UUID for this identity
 * @property string|null $provider_email
 */
class OauthAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
