<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\DB;

class CategoryCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'status_mapping' => $this->getGlobalStatusMappings(),
            'categories' => CategoryResource::collection($this->collection),
        ];
    }

    /**
     * Get all vendor status mappings (global, not per category)
     */
    private function getGlobalStatusMappings()
    {
        $mappings = DB::table('vendor_status_mappings')
            ->join('data_sources', 'vendor_status_mappings.data_source_id', '=', 'data_sources.id')
            ->whereNull('vendor_status_mappings.deleted_at')
            ->select(
                'data_sources.id',
                'data_sources.name',
                'data_sources.url',
                'vendor_status_mappings.id as mapping_id',
                'vendor_status_mappings.status_mapping',
                'vendor_status_mappings.created_at',
                'vendor_status_mappings.updated_at'
            )
            ->get();

        return $mappings->map(function ($mapping) {
            return [
                'id' => $mapping->id,
                'name' => $mapping->name,
                'url' => $mapping->url,
                'vendor_status_mapping' => [
                    'id' => $mapping->mapping_id,
                    'status_mapping' => json_decode($mapping->status_mapping, true),
                ],
            ];
        })->values()->toArray();
    }

    // Removed withResponse() - the controller already wraps in ResponseTrait::success()
    // Having both creates double-wrapped JSON: {success: true, data: {success: true, data: ...}}
}