<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ViduTemplate extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'resource' => 'array',
        'image_url' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function templateCategory()
    {
        return $this->belongsTo(TemplateCategory::class, 'template_category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
