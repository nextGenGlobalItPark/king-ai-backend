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
        'request_body_template' => 'array',
        'response_mapping' => 'array',
        'extra_params' => 'array',
        'is_active' => 'boolean',
    ];

    public function feature()
    {
        return $this->belongsTo(AiFeature::class, 'ai_feature_id');
    }

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if this is a JWT config
     */
    public function isJwtAuth(): bool
    {
        return $this->auth_type === 'jwt';
    }

    /**
     * Check if this is a static token config
     */
    public function isStaticTokenAuth(): bool
    {
        return in_array($this->auth_type, ['token', 'bearer', 'static_token']);
    }
}
