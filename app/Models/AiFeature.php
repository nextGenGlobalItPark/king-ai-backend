<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiFeature extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function configs()
    {
        return $this->hasMany(AiFeatureConfig::class, 'ai_feature_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function templates()
    {
        return $this->hasMany(Template::class, 'ai_feature_id');
    }
}
