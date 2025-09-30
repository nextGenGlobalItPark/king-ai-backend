<?php

namespace App\Http\Controllers;

use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Models\KingAiTemplateApi;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TemplateController extends Controller
{
    use ResponseTrait;

    /**
     * Display a listing of the templates.
     */
    public function index()
    {
        $templates = Template::with(['templateCategory', 'templateApis.dataSource'])->get();
        return $this->success(
            data: TemplateResource::collection($templates),
            message: 'Templates retrieved successfully'
        );
    }

    /**
     * Store a newly created template in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'credit_cost' => 'nullable|integer|min:0',
            'template_apis' => 'nullable|array',
            'template_apis.*.data_source_id' => 'required_with:template_apis|exists:data_sources,id',
            'template_apis.*.model' => 'nullable|string|max:255',
            'template_apis.*.style' => 'nullable|string|max:255',
            'template_apis.*.images' => 'nullable|string',
            'template_apis.*.prompt' => 'nullable|string',
            'template_apis.*.duration' => 'nullable|numeric|min:0',
            'template_apis.*.seed' => 'nullable|integer',
            'template_apis.*.aspect_ratio' => 'nullable|string|max:255',
            'template_apis.*.bgm' => 'nullable|string|max:255',
            'template_apis.*.resolution' => 'nullable|string|max:255',
            'template_apis.*.movement_amplitude' => 'nullable|string|max:255',
            'template_apis.*.off_peak' => 'nullable|string|max:255',
            'template_apis.*.payload' => 'nullable|string',
            'template_apis.*.request_url' => 'required_with:template_apis|string|url',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Validation failed'
            );
        }

        $template = DB::transaction(function () use ($request) {
            $template = Template::create([
                'name' => $request->name,
                'template_category_id' => $request->template_category_id,
                'credit_cost' => $request->credit_cost,
                'created_by' => auth()->id(),
            ]);

            if ($request->has('template_apis')) {
                foreach ($request->template_apis as $apiData) {
                    $template->templateApis()->create([
                        'data_source_id' => $apiData['data_source_id'],
                        'model' => $apiData['model'] ?? null,
                        'style' => $apiData['style'] ?? 'general',
                        'images' => $apiData['images'] ?? null,
                        'prompt' => $apiData['prompt'] ?? null,
                        'duration' => $apiData['duration'] ?? 5,
                        'seed' => $apiData['seed'] ?? 0,
                        'aspect_ratio' => $apiData['aspect_ratio'] ?? '16:9',
                        'bgm' => $apiData['bgm'] ?? 'false',
                        'resolution' => $apiData['resolution'] ?? '1080p',
                        'movement_amplitude' => $apiData['movement_amplitude'] ?? 'auto',
                        'off_peak' => $apiData['off_peak'] ?? 'false',
                        'payload' => $apiData['payload'] ?? null,
                        'request_url' => $apiData['request_url'],
                    ]);
                }
            }

            return $template;
        });

        return $this->success(
            data: new TemplateResource($template->load(['templateCategory', 'templateApis.dataSource'])),
            message: 'Template created successfully',
            status: 201
        );
    }

    /**
     * Display the specified template.
     */
    public function show($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:templates,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid template ID'
            );
        }

        $template = Template::with(['templateCategory', 'templateApis.dataSource'])->findOrFail($id);
        return $this->success(
            data: new TemplateResource($template),
            message: 'Template retrieved successfully'
        );
    }

    /**
     * Update the specified template in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|exists:templates,id',
            'name' => 'required|string|max:255',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'credit_cost' => 'nullable|integer|min:0',
            'template_apis' => 'nullable|array',
            'template_apis.*.data_source_id' => 'required_with:template_apis|exists:data_sources,id',
            'template_apis.*.model' => 'nullable|string|max:255',
            'template_apis.*.style' => 'nullable|string|max:255',
            'template_apis.*.images' => 'nullable|string',
            'template_apis.*.prompt' => 'nullable|string',
            'template_apis.*.duration' => 'nullable|numeric|min:0',
            'template_apis.*.seed' => 'nullable|integer',
            'template_apis.*.aspect_ratio' => 'nullable|string|max:255',
            'template_apis.*.bgm' => 'nullable|string|max:255',
            'template_apis.*.resolution' => 'nullable|string|max:255',
            'template_apis.*.movement_amplitude' => 'nullable|string|max:255',
            'template_apis.*.off_peak' => 'nullable|string|max:255',
            'template_apis.*.payload' => 'nullable|string',
            'template_apis.*.request_url' => 'required_with:template_apis|string|url',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Validation failed'
            );
        }

        $template = DB::transaction(function () use ($request, $id) {
            $template = Template::findOrFail($id);
            $template->update([
                'name' => $request->name,
                'template_category_id' => $request->template_category_id,
                'credit_cost' => $request->credit_cost,
            ]);

            if ($request->has('template_apis')) {
                $template->templateApis()->delete(); // Soft delete existing APIs
                foreach ($request->template_apis as $apiData) {
                    $template->templateApis()->create([
                        'data_source_id' => $apiData['data_source_id'],
                        'model' => $apiData['model'] ?? null,
                        'style' => $apiData['style'] ?? 'general',
                        'images' => $apiData['images'] ?? null,
                        'prompt' => $apiData['prompt'] ?? null,
                        'duration' => $apiData['duration'] ?? 5,
                        'seed' => $apiData['seed'] ?? 0,
                        'aspect_ratio' => $apiData['aspect_ratio'] ?? '16:9',
                        'bgm' => $apiData['bgm'] ?? 'false',
                        'resolution' => $apiData['resolution'] ?? '1080p',
                        'movement_amplitude' => $apiData['movement_amplitude'] ?? 'auto',
                        'off_peak' => $apiData['off_peak'] ?? 'false',
                        'payload' => $apiData['payload'] ?? null,
                        'request_url' => $apiData['request_url'],
                    ]);
                }
            }

            return $template;
        });

        return $this->success(
            data: new TemplateResource($template->load(['templateCategory', 'templateApis.dataSource'])),
            message: 'Template updated successfully'
        );
    }

    /**
     * Remove the specified template from storage.
     */
    public function destroy($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:templates,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid template ID'
            );
        }

        $template = Template::findOrFail($id);
        $template->delete(); // Soft delete, cascades to king_ai_template_apis
        return $this->success(
            message: 'Template and associated APIs deleted successfully'
        );
    }
}