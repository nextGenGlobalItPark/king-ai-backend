<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class TemplateCategory extends Model
{
    use HasFactory, HasApiTokens;
    protected $guarded = [''];

    public function dataSources()
    {
        return $this->belongsToMany(DataSource::class, 'template_category_data_sources', 'template_category_id', 'datasource_id')
            ->withPivot('created_by', 'source_db_id')
            ->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
