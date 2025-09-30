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