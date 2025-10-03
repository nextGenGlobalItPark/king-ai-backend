<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiJob extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];
    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'status' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
