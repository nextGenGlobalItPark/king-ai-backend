<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];

    public function templateCategory()
    {
        return $this->belongsTo(TemplateCategory::class, 'template_category_id');
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
}
