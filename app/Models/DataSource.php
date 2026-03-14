<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataSource extends Model
{
    use HasFactory, SoftDeletes;
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
        $value = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
        return $query->where($field, 'like', "%{$value}%");
    }

    public function vendorStatusMapping()
    {
        return $this->hasOne(VendorStatusMapping::class, 'data_source_id');
    }
}
