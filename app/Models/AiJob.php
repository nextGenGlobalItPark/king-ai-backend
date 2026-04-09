<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiJob extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [''];
    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'status' => 'string',
        'hidden_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class);
    }

    public function aiFeature()
    {
        return $this->belongsTo(AiFeature::class, 'feature', 'name');
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Scope: only visible (not hidden by user) jobs.
     */
    public function scopeVisible($query)
    {
        return $query->whereNull('hidden_at');
    }

    /**
     * Check if assets have been cleaned up.
     */
    public function assetsCleanedUp(): bool
    {
        return !empty($this->result['cleaned_up_at']);
    }
}
