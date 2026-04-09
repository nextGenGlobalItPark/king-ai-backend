<?php

namespace App\Http\Controllers;

use App\Events\TemplateEvent;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\AiFeature;
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
            $query = Template::with(['templateCategories', 'aiFeature.dataSource', 'aiFeature.configs.dataSource', 'createdBy', 'templateContents'])
                ->when($request->has('ai_feature_id'), fn($q) => $q->where('ai_feature_id', $request->ai_feature_id))
                ->when($request->has('data_source_id'), fn($q) => $q->where('data_source_id', $request->data_source_id))
                ->when($request->has('created_by'), fn($q) => $q->where('created_by', $request->created_by))
                ->when($request->has('category_id'), function($q) use ($request) {
                    $q->whereHas('templateCategories', function($query) use ($request) {
                        $query->where('template_categories.id', $request->category_id);
                    });
                })
                ->when($request->has('category_slug'), function($q) use ($request) {
                    $q->whereHas('templateCategories', function($query) use ($request) {
                        $query->where('template_category_slug', $request->category_slug);
                    });
                })
                ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->has('state'), fn($q) => $q->where('state', $request->state));

            $query->orderBy('sequence', 'asc');

            $templates = $query->paginate($request->get('per_page', 15));

            return $this->success(TemplateResource::collection($templates), 'Templates retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: null,
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

            // Auto-fill data_source_id from ai_feature
            $dataSourceId = $this->resolveDataSourceId($request->ai_feature_id);

            $template = Template::create([
                'name' => $request->name,
                'credit_cost' => $request->credit_cost,
                'ai_feature_id' => $request->ai_feature_id,
                'data_source_id' => $dataSourceId,
                'metadata' => $request->metadata,
                'is_active' => $request->get('is_active', true),
                'state' => $request->get('state', 'test'),
                'sequence' => $request->get('sequence', 0),
                'created_by' => auth()->id(),
            ]);

            // Attach categories (many-to-many)
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

            $template->load(['templateCategories', 'aiFeature.dataSource', 'aiFeature.configs.dataSource', 'createdBy', 'templateContents']);

            event(new TemplateEvent($template, 'created'));

            return $this->success(
                new TemplateResource($template),
                'Template created successfully with ' . count($uploadedContents) . ' contents.',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: null,
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
            $template->load(['templateCategories', 'aiFeature.dataSource', 'aiFeature.configs.dataSource', 'createdBy', 'templateContents']);

            return $this->success(new TemplateResource($template), 'Template retrieved successfully');
        } catch (\Exception $e) {
            return $this->error(
                errors: null,
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

            $updateData = $request->only([
                'name',
                'credit_cost',
                'ai_feature_id',
                'metadata',
                'is_active',
                'state',
                'sequence'
            ]);

            // Auto-fill data_source_id if ai_feature_id is being updated
            if (isset($updateData['ai_feature_id'])) {
                $updateData['data_source_id'] = $this->resolveDataSourceId($updateData['ai_feature_id']);
            }

            $template->update($updateData);

            // Sync categories (many-to-many)
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
                        $oldContent = TemplateContent::findOrFail($contentId);

                        if ($oldContent->template_id !== $template->id) {
                            throw new \Exception('Content does not belong to this template.', 403);
                        }

                        if ($hasFile) {
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
                            $updates = ['text' => $contentData['text'] ?? null];
                            if (isset($contentData['type'])) {
                                $updates['template_content_type'] = $contentData['type'];
                            }
                            $oldContent->update($updates);
                        }
                    } else {
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

            $template->load(['templateCategories', 'aiFeature.dataSource', 'aiFeature.configs.dataSource', 'createdBy', 'templateContents']);

            event(new TemplateEvent($template, 'updated'));

            return $this->success(new TemplateResource($template), 'Template updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: null,
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

            $contents = $template->templateContents;
            foreach ($contents as $content) {
                if (Storage::disk('public')->exists($content->template_content)) {
                    Storage::disk('public')->delete($content->template_content);
                }
                $content->delete();
            }

            $template->templateCategories()->detach();
            $template->delete();

            DB::commit();

            event(new TemplateEvent($template, 'deleted'));

            return $this->success(
                data: null,
                message: 'Template deleted successfully',
                status: Response::HTTP_NO_CONTENT
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: null,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Resolve data_source_id from ai_feature.
     */
    private function resolveDataSourceId(int $aiFeatureId): ?int
    {
        $feature = AiFeature::find($aiFeatureId);
        return $feature?->data_source_id;
    }
}
