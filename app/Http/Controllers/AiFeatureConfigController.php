<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiFeatureConfigRequest;
use App\Http\Requests\UpdateAiFeatureConfigRequest;
use App\Models\AiFeatureConfig;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiFeatureConfigController extends Controller
{
    use ResponseTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $configs = AiFeatureConfig::with('feature')
                ->when($request->has('feature_id'), fn($q) => $q->where('ai_feature_id', $request->feature_id))
                ->when($request->has('vendor'), fn($q) => $q->where('vendor_name', $request->vendor))
                ->when($request->has('active_only'), fn($q) => $q->where('is_active', true))
                ->orderBy('priority')
                ->paginate($request->get('per_page', 15));

            return $this->success(
                data: $configs,
                message: 'Configs retrieved successfully',
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
     * Store a newly created resource in storage.
     */
    public function store(StoreAiFeatureConfigRequest $request)
    {
        try {
            $config = AiFeatureConfig::create($request->validated());
            return $this->success(
                data: $config->load('feature'),
                message: 'Config created successfully',
                status: Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(AiFeatureConfig $aiFeatureConfig)
    {
        try {
            return $this->success(
                data: $aiFeatureConfig->load('feature'),
                message: 'Config retrieved successfully',
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
     * Update the specified resource in storage.
     */
    public function update(UpdateAiFeatureConfigRequest $request, AiFeatureConfig $aiFeatureConfig)
    {
        try {
            $aiFeatureConfig->update($request->validated());
            return $this->success(
                data: $aiFeatureConfig->load('feature'),
                message: 'Config updated successfully',
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
     * Remove the specified resource from storage.
     */
    public function destroy(AiFeatureConfig $aiFeatureConfig)
    {

        try {
            $aiFeatureConfig->delete();
            return $this->success(
                message: 'Config deleted successfully',
                status: Response::HTTP_NO_CONTENT
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}
