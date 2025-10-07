<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KingAiTemplateApi extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }
}
