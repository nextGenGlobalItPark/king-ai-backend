<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiFeatureRequest;
use App\Http\Requests\UpdateAiFeatureRequest;
use App\Models\AiFeature;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiFeatureController extends Controller
{
    use ResponseTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $features = AiFeature::withCount('configs')
                ->when($request->has('active_only'), fn($q) => $q->where('is_active', true))
                ->paginate($request->get('per_page', 15));
            return $this->success(
                data: $features,
                message: 'Features retrieved successfully',
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
    public function store(StoreAiFeatureRequest $request)
    {
        try {
            $feature = AiFeature::create($request->validated());
            return $this->success(
                data: $feature->loadCount('configs'),
                message: 'Features created successfully',
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
    public function show(AiFeature $aiFeature)
    {
        try {
            return $this->success(
                data: $aiFeature->load(['configs' => fn($q) => $q->where('is_active', true)]),
                message: 'Features retrieved successfully',
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
    public function update(UpdateAiFeatureRequest $request, AiFeature $aiFeature)
    {
        try {
            $aiFeature->update($request->validated());

            return $this->success(
                data: $aiFeature->loadCount('configs'),
                message: 'Features Updated successfully',
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
    public function destroy(AiFeature $aiFeature)
    {
        try {
            $aiFeature->delete();
            return $this->success(
                message: 'Features deleted successfully',
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
