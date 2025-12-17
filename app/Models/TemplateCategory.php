<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplateCategory extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function dataSources()
    {
        return $this->belongsToMany(
            DataSource::class,
            'template_category_data_sources',
            'template_category_id',
            'datasource_id'
        )
            ->withPivot('created_by', 'source_db_id')
            ->withTimestamps();
    }

    public function templates()
    {
        return $this->belongsToMany(
            Template::class,
            'template_category_template',
            'template_category_id',
            'template_id'
        )->withTimestamps();
    }


    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to search by field
     */
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
