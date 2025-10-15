<?php

namespace App\Http\Controllers;

use App\Events\TemplateEvent;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Models\TemplateContent;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TemplateController extends Controller
{
    use ResponseTrait;

    /**
     * Display a listing of the templates.
     */
    public function index(Request $request)
    {
        try {
            $query = Template::with(['templateCategory', 'aiFeature', 'createdBy', 'templateContents'])
                ->when($request->has('ai_feature_id'), fn($q) => $q->where('ai_feature_id', $request->ai_feature_id))
                ->when($request->has('category_id'), fn($q) => $q->where('template_category_id', $request->category_id))
                ->when($request->has('created_by'), fn($q) => $q->where('created_by', $request->created_by));

            $templates = $query->paginate($request->get('per_page', 15));

            return $this->success(TemplateResource::collection($templates), 'Templates retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Store a newly created template in storage.
     */
    public function store(StoreTemplateRequest $request)
    {
        try {
            DB::beginTransaction();
            $template = Template::create([
                'name' => $request->name,
                'template_category_id' => $request->template_category_id,
                'credit_cost' => $request->credit_cost,
                'ai_feature_id' => $request->ai_feature_id,
                'created_by' => auth()->id(),
            ]);

            $uploadedContents = [];
            $contents = $request->contents;  // Array of content data
            for ($i = 0; $i < count($contents); $i++) {
                $contentData = $contents[$i];
                $fileKey = "contents.{$i}.file";

                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $type = $contentData['type'];
                    $text = $contentData['text'] ?? null;

                    // Generate unique filename
                    $extension = $file->getClientOriginalExtension();
                    $filename = Str::uuid() . '.' . $extension;
                    $path = $file->storeAs('templates', $filename, 'public');  // Store in storage/app/public/templates

                    $templateContent = TemplateContent::create([
                        'template_id' => $template->id,
                        'template_content_type' => $type,
                        'template_content' => $path,  // Store relative path; access via Storage::url($path)
                        'text' => $text,
                        'created_by' => auth()->id(),
                    ]);

                    $uploadedContents[] = $templateContent;
                }
            }
            DB::commit();
            $template->load(['templateCategory', 'aiFeature', 'createdBy', 'templateContents']);

            event(new TemplateEvent($template, 'created'));
            return $this->success(
                new TemplateResource($template),
                'Template created successfully with ' . count($uploadedContents) . ' contents.',
                Response::HTTP_CREATED
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
     * Display the specified template.
     */
    public function show(Template $template)
    {
        try {
            $template->load(['templateCategory', 'aiFeature', 'createdBy', 'templateContents']);

            return $this->success(new TemplateResource($template), 'Template retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Update the specified template in storage.
     */
    public function update(UpdateTemplateRequest $request, Template $template)
    {
        try {
            DB::beginTransaction();
            // Handle basic template update
            $template->update($request->only(['name', 'template_category_id', 'credit_cost', 'ai_feature_id']));

            // Handle contents update (add new ones; for full replace, delete old first if needed)
            if ($request->has('contents')) {
                foreach ($request->contents as $i => $contentData) {
                    $fileKey = "contents.{$i}.file";
                    $hasFile = $request->hasFile($fileKey);
                    $contentId = $contentData['id'] ?? null;

                    if ($contentId) {
                        // Existing content
                        $oldContent = TemplateContent::findOrFail($contentId);

                        // Security: Ensure it belongs to this template
                        if ($oldContent->template_id !== $template->id) {
                            throw new \Exception('Content does not belong to this template.', 403);
                        }

                        if ($hasFile) {
                            // Replace file: Delete old, upload new, update path/type/text
                            if (Storage::disk('public')->exists($oldContent->template_content)) {
                                Storage::disk('public')->delete($oldContent->template_content);
                            }

                            $file = $request->file($fileKey);
                            $type = $contentData['type'];  // Assumes type provided (validated with required_with:file)
                            $text = $contentData['text'] ?? null;

                            $extension = $file->getClientOriginalExtension();
                            $filename = Str::uuid() . '.' . $extension;
                            $path = $file->storeAs('templates', $filename, 'public');

                            $oldContent->update([
                                'template_content_type' => $type,
                                'template_content' => $path,
                                'text' => $text,
                            ]);
                        } else {
                            // Update text only (optionally update type if provided, even without file)
                            $updates = ['text' => $contentData['text'] ?? null];
                            if (isset($contentData['type'])) {
                                $updates['template_content_type'] = $contentData['type'];
                            }
                            $oldContent->update($updates);
                        }
                    } else {
                        // New content (only if file provided)
                        if ($hasFile) {
                            $file = $request->file($fileKey);
                            $type = $contentData['type'];
                            $text = $contentData['text'] ?? null;

                            $extension = $file->getClientOriginalExtension();
                            $filename = Str::uuid() . '.' . $extension;
                            $path = $file->storeAs('templates', $filename, 'public');

                            TemplateContent::create([
                                'template_id' => $template->id,
                                'template_content_type' => $type,
                                'template_content' => $path,
                                'text' => $text,
                                'created_by' => auth()->id(),
                            ]);
                        }
                    }
                }
            }
            DB::commit();
            $template->load(['templateCategory', 'aiFeature', 'createdBy', 'templateContents']);

            event(new TemplateEvent($template, 'updated'));

            return $this->success(new TemplateResource($template), 'Template updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Remove the specified template from storage.
     */
    public function destroy(Template $template)
    {
        try {
            DB::beginTransaction();
            event(new TemplateEvent($template, 'deleted'));
            // Permanent delete: Hard-delete files and records
            $contents = $template->templateContents;  // Eager load to access
            foreach ($contents as $content) {
                if (Storage::disk('public')->exists($content->template_content)) {
                    Storage::disk('public')->delete($content->template_content);
                }
                $content->forceDelete();  // Hard delete contents
            }
            $template->forceDelete();  // Hard delete template
            DB::commit();
            return $this->success(data:null, message: 'Template deleted successfully', status: Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}