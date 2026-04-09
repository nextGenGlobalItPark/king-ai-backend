<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Auto-fill data_source_id from the AI feature on save.
     */
    protected static function booted(): void
    {
        static::saving(function (Template $template) {
            if ($template->ai_feature_id) {
                $feature = AiFeature::find($template->ai_feature_id);
                if ($feature && $feature->data_source_id) {
                    $template->data_source_id = $feature->data_source_id;
                }
            }
        });
    }

    public function templateCategories()
    {
        return $this->belongsToMany(
            TemplateCategory::class,
            'template_category_template',
            'template_id',
            'template_category_id'
        )->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function templateContents()
    {
        return $this->hasMany(TemplateContent::class, 'template_id');
    }

    public function aiFeature()
    {
        return $this->belongsTo(AiFeature::class, 'ai_feature_id');
    }

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    /**
     * Scope a query to only include active templates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include live templates
     */
    public function scopeLive($query)
    {
        return $query->where('state', 'live');
    }

    /**
     * Scope a query to only include test templates
     */
    public function scopeTest($query)
    {
        return $query->where('state', 'test');
    }

    public function scopeWhereLike($query, $field, $value)
    {
        $value = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
        return $query->where($field, 'like', "%{$value}%");
    }
}
