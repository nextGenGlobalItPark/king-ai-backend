<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorStatusMappingRequest;
use App\Http\Requests\UpdateVendorStatusMappingRequest;
use App\Models\VendorStatusMapping;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VendorStatusMappingController extends Controller
{
    use ResponseTrait;

    /**
     * List all vendor status mappings
     */
    public function index(Request $request)
    {
        try {
            $query = VendorStatusMapping::with(['dataSource', 'createdBy']);

            if ($request->has('data_source_id')) {
                $query->where('data_source_id', $request->data_source_id);
            }

            $mappings = $query->get();

            return $this->success(
                data: $mappings->map(function ($mapping) {
                    return [
                        'id' => $mapping->id,
                        'data_source' => [
                            'id' => $mapping->dataSource->id,
                            'name' => $mapping->dataSource->name,
                        ],
                        'status_mapping' => $mapping->status_mapping,
                        'created_by' => $mapping->createdBy ? [
                            'id' => $mapping->createdBy->id,
                            'name' => $mapping->createdBy->name,
                        ] : null,
                        'created_at' => $mapping->created_at,
                        'updated_at' => $mapping->updated_at,
                    ];
                }),
                message: 'Vendor status mappings retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Show specific vendor mapping
     */
    public function show(VendorStatusMapping $vendorStatusMapping)
    {
        try {
            $vendorStatusMapping->load(['dataSource', 'createdBy']);

            return $this->success(
                data: [
                    'id' => $vendorStatusMapping->id,
                    'data_source' => [
                        'id' => $vendorStatusMapping->dataSource->id,
                        'name' => $vendorStatusMapping->dataSource->name,
                    ],
                    'status_mapping' => $vendorStatusMapping->status_mapping,
                    'created_by' => $vendorStatusMapping->createdBy ? [
                        'id' => $vendorStatusMapping->createdBy->id,
                        'name' => $vendorStatusMapping->createdBy->name,
                    ] : null,
                    'created_at' => $vendorStatusMapping->created_at,
                    'updated_at' => $vendorStatusMapping->updated_at,
                ],
                message: 'Vendor status mapping retrieved successfully',
                status: Response::HTTP_OK
            );

        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Create new vendor status mapping
     */
    public function store(StoreVendorStatusMappingRequest $request)
    {
        try {
            DB::beginTransaction();

            $mapping = VendorStatusMapping::create([
                'data_source_id' => $request->data_source_id,
                'status_mapping' => $request->status_mapping,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            Log::info('Vendor status mapping created', [
                'mapping_id' => $mapping->id,
                'data_source_id' => $mapping->data_source_id,
                'user_id' => auth()->id(),
            ]);

            $mapping->load(['dataSource', 'createdBy']);

            return $this->success(
                data: [
                    'id' => $mapping->id,
                    'data_source' => [
                        'id' => $mapping->dataSource->id,
                        'name' => $mapping->dataSource->name,
                    ],
                    'status_mapping' => $mapping->status_mapping,
                    'created_at' => $mapping->created_at,
                ],
                message: 'Vendor status mapping created successfully',
                status: Response::HTTP_CREATED
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Update vendor status mapping
     */
    public function update(UpdateVendorStatusMappingRequest $request, VendorStatusMapping $vendorStatusMapping)
    {
        try {
            DB::beginTransaction();

            $vendorStatusMapping->update([
                'status_mapping' => $request->status_mapping,
            ]);

            DB::commit();

            Log::info('Vendor status mapping updated', [
                'mapping_id' => $vendorStatusMapping->id,
                'data_source_id' => $vendorStatusMapping->data_source_id,
                'user_id' => auth()->id(),
            ]);

            $vendorStatusMapping->load(['dataSource']);

            return $this->success(
                data: [
                    'id' => $vendorStatusMapping->id,
                    'data_source' => [
                        'id' => $vendorStatusMapping->dataSource->id,
                        'name' => $vendorStatusMapping->dataSource->name,
                    ],
                    'status_mapping' => $vendorStatusMapping->status_mapping,
                    'updated_at' => $vendorStatusMapping->updated_at,
                ],
                message: 'Vendor status mapping updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Delete vendor status mapping
     */
    public function destroy(VendorStatusMapping $vendorStatusMapping)
    {
        try {
            DB::beginTransaction();

            $dataSourceId = $vendorStatusMapping->data_source_id;
            $vendorStatusMapping->delete();

            DB::commit();

            Log::info('Vendor status mapping deleted', [
                'data_source_id' => $dataSourceId,
                'user_id' => auth()->id(),
            ]);

            return $this->success(
                message: 'Vendor status mapping deleted successfully',
                status: Response::HTTP_NO_CONTENT
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}