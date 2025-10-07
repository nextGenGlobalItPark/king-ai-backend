<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class DataSource extends Model
{
    use HasFactory, HasApiTokens;
    protected $guarded = [''];

    public function templateCategories()
    {
        return $this->belongsToMany(TemplateCategory::class, 'template_category_data_sources', 'datasource_id', 'template_category_id')
            ->withPivot('created_by', 'source_db_id')
            ->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeWhereLike($query, $field, $value)
    {
        return $query->where($field, 'like', "%{$value}%");
    }
}
