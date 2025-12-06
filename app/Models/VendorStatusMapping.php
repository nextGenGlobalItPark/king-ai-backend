<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class VendorStatusMapping extends Model
{
    use HasFactory, HasApiTokens, SoftDeletes;
    protected $guarded = [''];

    protected $casts = [
        'status_mapping' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Map vendor status to internal status
     */
    public function mapStatus(string $vendorStatus): string
    {
        $mapping = $this->status_mapping;

        foreach ($mapping as $internalStatus => $vendorStatuses) {
            $vendorStatuses = is_array($vendorStatuses) ? $vendorStatuses : [$vendorStatuses];

            if (in_array($vendorStatus, $vendorStatuses)) {
                return $internalStatus;
            }
        }

        // Default fallback
        return 'processing';
    }
}
