<?php

namespace App\Http\Controllers;

use App\Http\Resources\TemplateResource;
use App\Models\AiJob;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryController extends Controller
{
    use ResponseTrait;

    /**
     * List user's generation history (paginated).
     *
     * GET /api/history
     *   ?status=completed|failed|processing
     *   ?feature=image2video|text2video|effects
     *   ?per_page=15
     *   ?page=1
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AiJob::with(['template.templateContents'])
                // List view never uses the `input` JSON column (only the
                // detailed show() does). Excluding it keeps rows narrow and
                // avoids dragging large payloads through sorting/pagination.
                // `template_id` is required for the template eager-load.
                ->select([
                    'id',
                    'queue_job_id',
                    'feature',
                    'status',
                    'progress',
                    'credit_cost',
                    'template_id',
                    'result',
                    'user_id',
                    'created_at',
                    'updated_at',
                ])
                ->where('user_id', auth()->id())
                ->whereNull('hidden_at')
                ->orderBy('created_at', 'desc');

            // Optional filters
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('feature')) {
                $query->where('feature', $request->input('feature'));
            }

            $perPage = min((int) $request->input('per_page', 15), 50); // Cap at 50
            $jobs = $query->paginate($perPage);

            // Transform each job for the response
            $items = collect($jobs->items())->map(fn ($job) => $this->formatHistoryItem($job));

            return $this->success(
                data: [
                    'items' => $items,
                    'pagination' => [
                        'current_page' => $jobs->currentPage(),
                        'last_page' => $jobs->lastPage(),
                        'per_page' => $jobs->perPage(),
                        'total' => $jobs->total(),
                    ],
                ],
                message: 'History retrieved'
            );
        } catch (\Exception $e) {
            Log::error('History index failed', ['error' => $e->getMessage()]);
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Get single job detail.
     *
     * GET /api/history/{jobId}
     */
    public function show(string $jobId): JsonResponse
    {
        try {
            $job = AiJob::with(['template.templateContents'])
                ->where('queue_job_id', $jobId)
                ->where('user_id', auth()->id())
                ->whereNull('hidden_at')
                ->first();

            if (!$job) {
                return $this->error(
                    errors: null,
                    message: 'Generation not found',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            return $this->success(
                data: $this->formatHistoryItem($job, detailed: true),
                message: 'Generation details retrieved'
            );
        } catch (\Exception $e) {
            Log::error('History show failed', ['error' => $e->getMessage()]);
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Download a specific asset from a completed job.
     *
     * GET /api/history/{jobId}/download?asset=0
     *
     * The asset query param is the index in the assets array (default 0).
     * Returns the file as a download response with proper headers.
     */
    public function download(Request $request, string $jobId): StreamedResponse|JsonResponse
    {
        try {
            $job = AiJob::where('queue_job_id', $jobId)
                ->where('user_id', auth()->id())
                ->where('status', 'completed')
                ->first();

            if (!$job) {
                return $this->error(
                    errors: null,
                    message: 'Completed generation not found',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            $result = $job->result;
            $assets = $result['assets'] ?? [];
            $assetIndex = (int) $request->input('asset', 0);

            if (!isset($assets[$assetIndex])) {
                return $this->error(
                    errors: null,
                    message: 'Asset not found',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            $asset = $assets[$assetIndex];
            $url = $asset['url'] ?? null;

            if (!$url) {
                return $this->error(
                    errors: null,
                    message: 'Asset URL not available',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            // Check if the asset is stored locally
            $localPath = $this->extractLocalPath($url);

            if ($localPath && Storage::disk('public')->exists($localPath)) {
                // Serve from local storage
                $filename = $this->buildDownloadFilename($job, $assetIndex);
                $mimeType = Storage::disk('public')->mimeType($localPath) ?? 'application/octet-stream';

                return Storage::disk('public')->download($localPath, $filename, [
                    'Content-Type' => $mimeType,
                ]);
            }

            // Fall back to redirect to vendor URL (for assets not yet downloaded)
            return redirect()->away($url);

        } catch (\Exception $e) {
            Log::error('History download failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Hide a job from user's history (soft-hide, not delete).
     *
     * DELETE /api/history/{jobId}
     */
    public function destroy(string $jobId): JsonResponse
    {
        try {
            $job = AiJob::where('queue_job_id', $jobId)
                ->where('user_id', auth()->id())
                ->whereNull('hidden_at')
                ->first();

            if (!$job) {
                return $this->error(
                    errors: null,
                    message: 'Generation not found',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            $job->update(['hidden_at' => now()]);

            return $this->success(
                message: 'Removed from history'
            );
        } catch (\Exception $e) {
            Log::error('History hide failed', ['error' => $e->getMessage()]);
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Format an AiJob for history API response.
     */
    private function formatHistoryItem(AiJob $job, bool $detailed = false): array
    {
        $item = [
            'job_id' => $job->queue_job_id,
            'feature' => $job->feature,
            'status' => $job->status,
            'progress' => $job->progress ?? 0,
            'credit_cost' => $job->credit_cost,
            'template' => $job->template ? $this->formatTemplate($job->template) : null,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ];

        if ($job->status === 'completed') {
            $result = $job->result;
            $assets = $result['assets'] ?? [];

            // Build a clean videos array for the client
            $videos = [];
            foreach ($assets as $index => $asset) {
                $video = [
                    'url' => $asset['url'] ?? null,
                    'thumbnail' => $asset['thumbnail'] ?? null,
                    'duration' => $asset['duration'] ?? null,
                ];

                if ($detailed) {
                    $video['id'] = $asset['id'] ?? null;
                    // Include download URL for convenience.
                    // We use the foreach $index directly — array_search would do a loose
                    // comparison across all asset arrays and is both slower and brittle
                    // (e.g., it returns false for any duplicate-shaped asset).
                    $video['download_url'] = $job->queue_job_id
                        ? url("/api/history/{$job->queue_job_id}/download?asset={$index}")
                        : null;
                }

                $videos[] = $video;
            }

            $item['result'] = ['videos' => $videos];

            // Quick access to first thumbnail for list view
            if (!$detailed && !empty($assets[0]['thumbnail'])) {
                $item['thumbnail'] = $assets[0]['thumbnail'];
            }

        } elseif ($job->status === 'failed') {
            $item['error'] = $job->result['error'] ?? 'Unknown error';
        }

        // Include input on detailed view
        if ($detailed) {
            $item['input'] = $job->input;
        }

        return $item;
    }

    /**
     * Format template with metadata (resolved URLs) and contents for history response.
     */
    private function formatTemplate($template): array
    {
        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'credit_cost' => $template->credit_cost,
        ];

        // Include template contents with full URLs
        $contents = [];
        if ($template->relationLoaded('templateContents')) {
            $contents = $template->templateContents->map(function ($content) {
                return [
                    'id' => $content->id,
                    'type' => $content->template_content_type,
                    'url' => TemplateResource::buildContentUrl($content->template_content),
                    'text' => $content->text,
                ];
            })->values()->toArray();
        }
        $data['template_contents'] = $contents;

        // Include metadata with resolved sample image/preview URLs
        $metadata = $template->metadata;
        if (!empty($metadata) && $template->relationLoaded('templateContents')) {
            $allContents = $template->templateContents->values();

            // Resolve preview_video_content_index → URL
            if (isset($metadata['preview_video_content_index'])) {
                $index = $metadata['preview_video_content_index'];
                if (isset($allContents[$index])) {
                    $metadata['preview_video_url'] = TemplateResource::buildContentUrl(
                        $allContents[$index]->template_content
                    );
                }
                unset($metadata['preview_video_content_index']);
            }

            // Resolve sample_content_index in input fields → URLs
            if (!empty($metadata['guide']['input_fields'])) {
                foreach ($metadata['guide']['input_fields'] as &$field) {
                    if (isset($field['sample_content_index'])) {
                        $index = $field['sample_content_index'];
                        if (isset($allContents[$index])) {
                            $field['sample_image_url'] = TemplateResource::buildContentUrl(
                                $allContents[$index]->template_content
                            );
                        }
                        unset($field['sample_content_index']);
                    }
                }
                unset($field);
            }
        }
        $data['metadata'] = $metadata;

        return $data;
    }

    /**
     * Extract the relative storage path from a full URL.
     *
     * e.g., "https://king-ai.nxtgenitpark.com/storage/generations/1/abc-123/video_0_xyz.mp4"
     *       → "generations/1/abc-123/video_0_xyz.mp4"
     */
    private function extractLocalPath(string $url): ?string
    {
        $storageUrl = Storage::disk('public')->url('');
        $storageUrl = rtrim($storageUrl, '/') . '/';

        if (str_starts_with($url, $storageUrl)) {
            return substr($url, strlen($storageUrl));
        }

        return null;
    }

    /**
     * Build a user-friendly download filename.
     */
    private function buildDownloadFilename(AiJob $job, int $assetIndex): string
    {
        $feature = str_replace(['/', '\\', ' '], '-', $job->feature);
        $date = $job->created_at->format('Y-m-d');
        $ext = 'mp4'; // Default; could be smarter by checking actual file

        $result = $job->result;
        $assets = $result['assets'] ?? [];
        if (isset($assets[$assetIndex]['url'])) {
            $parsedPath = parse_url($assets[$assetIndex]['url'], PHP_URL_PATH);
            $detectedExt = pathinfo($parsedPath, PATHINFO_EXTENSION);
            if (in_array($detectedExt, ['mp4', 'mov', 'webm', 'avi'])) {
                $ext = $detectedExt;
            }
        }

        return "{$feature}_{$date}_{$assetIndex}.{$ext}";
    }
}