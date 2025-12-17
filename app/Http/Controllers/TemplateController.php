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
            // ✅ UPDATED: Load templateCategories (many-to-many) instead of templateCategory
            $query = Template::with(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents'])
                ->when($request->has('ai_feature_id'), fn($q) => $q->where('ai_feature_id', $request->ai_feature_id))
                ->when($request->has('data_source_id'), fn($q) => $q->where('data_source_id', $request->data_source_id))
                ->when($request->has('created_by'), fn($q) => $q->where('created_by', $request->created_by))
                // ✅ NEW: Filter by category using many-to-many relationship
                ->when($request->has('category_id'), function($q) use ($request) {
                    $q->whereHas('templateCategories', function($query) use ($request) {
                        $query->where('template_categories.id', $request->category_id);
                    });
                })
                // ✅ NEW: Filter by category slug
                ->when($request->has('category_slug'), function($q) use ($request) {
                    $q->whereHas('templateCategories', function($query) use ($request) {
                        $query->where('template_category_slug', $request->category_slug);
                    });
                })
                // ✅ NEW: Filter by active status
                ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
                // ✅ NEW: Filter by state (test/live)
                ->when($request->has('state'), fn($q) => $q->where('state', $request->state));

            // Order by sequence
            $query->orderBy('sequence', 'asc');

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

            // ✅ UPDATED: Removed template_category_id, added data_source_id, is_active, state, sequence
            $template = Template::create([
                'name' => $request->name,
                'credit_cost' => $request->credit_cost,
                'ai_feature_id' => $request->ai_feature_id,
                'data_source_id' => $request->data_source_id,
                'is_active' => $request->get('is_active', true),
                'state' => $request->get('state', 'test'),
                'sequence' => $request->get('sequence', 0),
                'created_by' => auth()->id(),
            ]);

            // ✅ NEW: Attach categories (many-to-many)
            if ($request->has('category_ids')) {
                $template->templateCategories()->attach($request->category_ids);
            }

            // Handle template contents
            $uploadedContents = [];
            if ($request->has('contents')) {
                $contents = $request->contents;
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
                        $path = $file->storeAs('templates', $filename, 'public');

                        $templateContent = TemplateContent::create([
                            'template_id' => $template->id,
                            'template_content_type' => $type,
                            'template_content' => $path,
                            'text' => $text,
                            'created_by' => auth()->id(),
                        ]);

                        $uploadedContents[] = $templateContent;
                    }
                }
            }

            DB::commit();

            // ✅ UPDATED: Load templateCategories and dataSource
            $template->load(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents']);

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
            // ✅ UPDATED: Load templateCategories and dataSource
            $template->load(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents']);

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

            // ✅ UPDATED: Removed template_category_id, added data_source_id and other fields
            $updateData = $request->only([
                'name',
                'credit_cost',
                'ai_feature_id',
                'data_source_id',
                'is_active',
                'state',
                'sequence'
            ]);

            $template->update($updateData);

            // ✅ NEW: Sync categories (many-to-many)
            if ($request->has('category_ids')) {
                $template->templateCategories()->sync($request->category_ids);
            }

            // Handle contents update
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
                            // Replace file
                            if (Storage::disk('public')->exists($oldContent->template_content)) {
                                Storage::disk('public')->delete($oldContent->template_content);
                            }

                            $file = $request->file($fileKey);
                            $type = $contentData['type'];
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
                            // Update text only
                            $updates = ['text' => $contentData['text'] ?? null];
                            if (isset($contentData['type'])) {
                                $updates['template_content_type'] = $contentData['type'];
                            }
                            $oldContent->update($updates);
                        }
                    } else {
                        // New content
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

            // ✅ UPDATED: Load templateCategories and dataSource
            $template->load(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents']);

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

            // Delete associated files
            $contents = $template->templateContents;
            foreach ($contents as $content) {
                if (Storage::disk('public')->exists($content->template_content)) {
                    Storage::disk('public')->delete($content->template_content);
                }
                $content->forceDelete();
            }

            // ✅ NEW: Detach all categories before deleting template
            $template->templateCategories()->detach();

            // Delete template
            $template->forceDelete();

            DB::commit();

            return $this->success(
                data: null,
                message: 'Template deleted successfully',
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

    /**
     * ✅ NEW: Get templates by category
     */
    public function byCategory(Request $request, $categoryId)
    {
        try {
            $templates = Template::with(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents'])
                ->whereHas('templateCategories', function($query) use ($categoryId) {
                    $query->where('template_categories.id', $categoryId);
                })
                ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->has('state'), fn($q) => $q->where('state', $request->state))
                ->orderBy('sequence', 'asc')
                ->get();

            return $this->success(TemplateResource::collection($templates), 'Templates retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * ✅ NEW: Get templates by category slug
     */
    public function byCategorySlug(Request $request, $slug)
    {
        try {
            $templates = Template::with(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents'])
                ->whereHas('templateCategories', function($query) use ($slug) {
                    $query->where('template_category_slug', $slug);
                })
                ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->has('state'), fn($q) => $q->where('state', $request->state))
                ->orderBy('sequence', 'asc')
                ->get();

            return $this->success(TemplateResource::collection($templates), 'Templates retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * ✅ NEW: Get templates by multiple categories
     */
    public function byCategories(Request $request)
    {
        try {
            $request->validate([
                'category_ids' => 'required|array',
                'category_ids.*' => 'integer|exists:template_categories,id',
            ]);

            $templates = Template::with(['templateCategories', 'aiFeature', 'dataSource', 'createdBy', 'templateContents'])
                ->whereHas('templateCategories', function($query) use ($request) {
                    $query->whereIn('template_categories.id', $request->category_ids);
                })
                ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->has('state'), fn($q) => $q->where('state', $request->state))
                ->orderBy('sequence', 'asc')
                ->get()
                ->unique('id'); // Remove duplicates if template is in multiple requested categories

            return $this->success(TemplateResource::collection($templates), 'Templates retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}