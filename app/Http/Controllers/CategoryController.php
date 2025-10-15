<?php

namespace App\Http\Controllers;

use App\Events\CategoryEvent;
use App\Http\Resources\CategoryResource;
use App\Models\TemplateCategory;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    use ResponseTrait;
    /**
     * Display a listing of the template categories.
     */
    public function index()
    {
        try {
            $categories = TemplateCategory::with([
                'dataSources',
                'templates.createdBy',
                'templates.aiFeature.configs.dataSource',
                'templates.templateContents'
            ])->get();
            return $this->success(
                data: CategoryResource::collection($categories),
                message: 'Template categories retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

    }

    /**
     * Store a newly created template category in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_category_name' => 'required|string|max:255',
            'data_source_ids' => 'nullable|array',
            'data_source_ids.*' => 'exists:data_sources,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {
            DB::beginTransaction();
            $category = TemplateCategory::create([
                'template_category_name' => $request->template_category_name,
                'template_category_slug' => Str::slug($request->template_category_name),
                'created_by' => auth()->id(),
            ]);

            if ($request->has('data_source_ids')) {
                $category->dataSources()->sync($request->data_source_ids);
            }
            DB::commit();

            event(new CategoryEvent($category, 'created'));

            return $this->success(
                data: new CategoryResource($category->load('dataSources')),
                message: 'Template category created successfully',
                status: Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Display the specified template category.
     */
    public function show($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:template_categories,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid category ID'
            );
        }

        try {
            $category = TemplateCategory::with('dataSources')->findOrFail($id);
            return $this->success(
                data: new CategoryResource($category),
                message: 'Template category retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Update the specified template category in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|exists:template_categories,id',
            'template_category_name' => 'required|string|max:255|unique:template_categories,template_category_name,' . $id,
            'data_source_ids' => 'nullable|array',
            'data_source_ids.*' => 'exists:data_sources,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Validation failed'
            );
        }
        try {
            DB::beginTransaction();
            $category = TemplateCategory::findOrFail($id);
            $category->update([
                'template_category_name' => $request->template_category_name,
                'template_category_slug' => Str::slug($request->template_category_name),
            ]);

            if ($request->has('data_source_ids')) {
                $category->dataSources()->sync($request->data_source_ids);
            }
            DB::commit();

            event(new CategoryEvent($category, 'updated'));
            return $this->success(
                data: new CategoryResource($category->load('dataSources')),
                message: 'Template category updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Remove the specified template category from storage.
     */
    public function destroy($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:template_categories,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid category ID'
            );
        }
        try {
            DB::beginTransaction();
            $category = TemplateCategory::findOrFail($id);
            event(new CategoryEvent($category, 'deleted'));
            $category->delete();
            DB::commit();
            return $this->success(
                message: 'Template category deleted successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Detach a data source from a template category.
     */
    public function detachDataSource($templateCategoryId, $dataSourceId)
    {
        $validator = Validator::make([
            'template_category_id' => $templateCategoryId,
            'datasource_id' => $dataSourceId,
        ], [
            'template_category_id' => 'required|exists:template_categories,id',
            'datasource_id' => 'required|exists:data_sources,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid category or data source ID'
            );
        }
        try {
            $category = TemplateCategory::findOrFail($templateCategoryId);
            $exists = $category->dataSources()->where('datasource_id', $dataSourceId)->exists();

            if (!$exists) {
                return $this->error(
                    message: 'Data source is not associated with this category',
                    status: 404
                );
            }

            $category->dataSources()->detach($dataSourceId);

            return $this->success(
                message: 'Data source detached from template category successfully'
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}