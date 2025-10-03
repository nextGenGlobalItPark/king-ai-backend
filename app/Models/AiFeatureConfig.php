<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiFeatureConfig extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'standard_schema' => 'array',
        'param_mapping' => 'array',
        'default_params' => 'array',
        'extra_params' => 'array',
        'is_active' => 'boolean',
    ];

    public function feature()
    {
        return $this->belongsTo(AiFeature::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
