<?php

namespace App\Http\Controllers;

use App\Http\Resources\ViduTemplateResource;
use App\Models\Template;
use App\Models\ViduTemplate;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ViduTemplateController extends Controller
{
    use ResponseTrait;

    /**
     * Display a listing of the vidu templates.
     */
    public function index()
    {
        $viduTemplates = ViduTemplate::with(['template.templateContents', 'templateCategory'])->get();
        return $this->success(
            data: ViduTemplateResource::collection($viduTemplates),
            message: 'Vidu templates retrieved successfully'
        );
    }

    /**
     * Store a new vidu template and its corresponding template.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_name' => 'required|string|max:255',
            'template_credit_cost' => 'nullable|integer|min:0',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'resource_id' => 'required|integer',
            'resource' => 'nullable|array',
            'name' => 'required|string|max:255',
            'resolution' => 'required|string|max:255',
            'duration' => 'required|string|max:255',
            'aspect_ratio' => 'required|string|max:255',
            'credit_cost' => 'required|string|max:255',
            'scene' => 'required|string|max:255',
            'cover_url' => 'required|string|url',
            'type' => 'required|string|max:255',
            'video_url' => 'required|string|url',
            'image_count' => 'nullable|string|max:255',
            'image_url' => 'nullable|array',
            'person_count' => 'nullable|string|max:255',
            'attention' => 'nullable|string',
            'prompt_instruction' => 'nullable|string',
            'effect_boundary' => 'nullable|string',
            'request_body' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Validation failed'
            );
        }

        $viduTemplate = DB::transaction(function () use ($request) {
            $template = Template::create([
                'name' => $request->template_name,
                'template_category_id' => $request->template_category_id,
                'credit_cost' => $request->template_credit_cost,
                'created_by' => auth()->id(),
            ]);

            return ViduTemplate::create([
                'template_id' => $template->id,
                'resource_id' => $request->resource_id,
                'resource' => $request->resource,
                'name' => $request->name,
                'resolution' => $request->resolution,
                'duration' => $request->duration,
                'aspect_ratio' => $request->aspect_ratio,
                'credit_cost' => $request->credit_cost,
                'scene' => $request->scene,
                'template_category_id' => $request->template_category_id,
                'cover_url' => $request->cover_url,
                'type' => $request->type,
                'video_url' => $request->video_url,
                'image_count' => $request->image_count,
                'image_url' => $request->image_url,
                'person_count' => $request->person_count,
                'attention' => $request->attention,
                'prompt_instruction' => $request->prompt_instruction,
                'effect_boundary' => $request->effect_boundary,
                'request_body' => $request->request_body,
                'created_by' => auth()->id(),
            ]);
        });

        return $this->success(
            data: new ViduTemplateResource($viduTemplate->load(['template.templateContents', 'templateCategory'])),
            message: 'Vidu template created successfully',
            status: 201
        );
    }

    /**
     * Display the specified vidu template.
     */
    public function show($templateId)
    {
        $validator = Validator::make(['template_id' => $templateId], [
            'template_id' => 'required|exists:vidu_templates,template_id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid vidu template ID'
            );
        }

        $viduTemplate = ViduTemplate::with(['template.templateContents', 'templateCategory'])
            ->where('template_id', $templateId)
            ->firstOrFail();
        return $this->success(
            data: new ViduTemplateResource($viduTemplate),
            message: 'Vidu template retrieved successfully'
        );
    }

    /**
     * Update the specified vidu template and its corresponding template.
     */
    public function update(Request $request, $templateId)
    {
        $validator = Validator::make(array_merge($request->all(), ['template_id' => $templateId]), [
            'template_id' => 'required|exists:vidu_templates,template_id',
            'template_name' => 'required|string|max:255',
            'template_credit_cost' => 'nullable|integer|min:0',
            'template_category_id' => 'nullable|exists:template_categories,id',
            'resource_id' => 'required|integer',
            'resource' => 'nullable|array',
            'name' => 'required|string|max:255',
            'resolution' => 'required|string|max:255',
            'duration' => 'required|string|max:255',
            'aspect_ratio' => 'required|string|max:255',
            'credit_cost' => 'required|string|max:255',
            'scene' => 'required|string|max:255',
            'cover_url' => 'required|string|url',
            'type' => 'required|string|max:255',
            'video_url' => 'required|string|url',
            'image_count' => 'nullable|string|max:255',
            'image_url' => 'nullable|array',
            'person_count' => 'nullable|string|max:255',
            'attention' => 'nullable|string',
            'prompt_instruction' => 'nullable|string',
            'effect_boundary' => 'nullable|string',
            'request_body' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Validation failed'
            );
        }

        $viduTemplate = DB::transaction(function () use ($request, $templateId) {
            $viduTemplate = ViduTemplate::findOrFail($templateId);
            $template = Template::findOrFail($templateId);

            $template->update([
                'name' => $request->template_name,
                'template_category_id' => $request->template_category_id,
                'credit_cost' => $request->template_credit_cost,
            ]);

            $viduTemplate->update([
                'resource_id' => $request->resource_id,
                'resource' => $request->resource,
                'name' => $request->name,
                'resolution' => $request->resolution,
                'duration' => $request->duration,
                'aspect_ratio' => $request->aspect_ratio,
                'credit_cost' => $request->credit_cost,
                'scene' => $request->scene,
                'template_category_id' => $request->template_category_id,
                'cover_url' => $request->cover_url,
                'type' => $request->type,
                'video_url' => $request->video_url,
                'image_count' => $request->image_count,
                'image_url' => $request->image_url,
                'person_count' => $request->person_count,
                'attention' => $request->attention,
                'prompt_instruction' => $request->prompt_instruction,
                'effect_boundary' => $request->effect_boundary,
                'request_body' => $request->request_body,
            ]);

            return $viduTemplate;
        });

        return $this->success(
            data: new ViduTemplateResource($viduTemplate->load(['template.templateContents', 'templateCategory'])),
            message: 'Vidu template updated successfully'
        );
    }

    /**
     * Remove the specified vidu template and its corresponding template.
     */
    public function destroy($templateId)
    {
        $validator = Validator::make(['template_id' => $templateId], [
            'template_id' => 'required|exists:vidu_templates,template_id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                errors: $validator->errors(),
                message: 'Invalid vidu template ID'
            );
        }

        DB::transaction(function () use ($templateId) {
            $template = Template::findOrFail($templateId);
            $template->delete(); // Soft delete, cascades to vidu_templates and template_contents
            ViduTemplate::where('template_id', $templateId)->delete();
        });

        return $this->success(
            message: 'Vidu template and associated template deleted successfully'
        );
    }
}