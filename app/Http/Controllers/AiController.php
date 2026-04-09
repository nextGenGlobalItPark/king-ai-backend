<?php

namespace App\Http\Controllers;

use App\Jobs\AiGenerationJob;
use App\Models\AiFeature;
use App\Models\AiFeatureConfig;
use App\Models\AiJob;
use App\Models\Template;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AiController extends Controller
{
    use ResponseTrait;

    /**
     * Generate AI content (queues job).
     */
    public function generate(Request $request, string $feature): JsonResponse
    {
        try {
            $request->validate([
                'input' => 'required|array',
                'template_id' => 'nullable|integer|exists:templates,id',
            ]);
            $input = $request->input('input', []);

            $featureObj = AiFeature::with('dataSource')
                ->where('name', $feature)
                ->where('is_active', true)
                ->firstOrFail();
            $dataSourceId = $featureObj->data_source_id;
            $uuid = Str::uuid()->toString();

            // Resolve template and snapshot its credit cost
            $templateId = $request->input('template_id');
            $creditCost = null;
            if ($templateId) {
                $template = Template::find($templateId);
                $creditCost = $template?->credit_cost;
            }

            $jobInstance = new AiGenerationJob($feature, $input, auth()->id(), $dataSourceId, $uuid, $templateId, $creditCost);

            dispatch($jobInstance)->onQueue('ai_generation');

            return $this->success(
                data: ['job_id' => $uuid, 'vendor' => $dataSourceId],
                message: 'Generation queued',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: null,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Get job status with normalized response (scoped to current user).
     *
     * Returns a consistent structure regardless of vendor:
     * {
     *   "job_id": "uuid",
     *   "feature": "image2video",
     *   "status": "completed|processing|failed",
     *   "progress": 0-100,
     *   "result": {
     *     "videos": [
     *       {"id": "...", "url": "...", "duration": "5.0", "thumbnail": "..."}
     *     ]
     *   },
     *   "error": null,
     *   "created_at": "...",
     *   "updated_at": "..."
     * }
     */
    public function status(string $jobId): JsonResponse
    {
        try {
            $job = AiJob::where('queue_job_id', $jobId)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            return $this->success(
                data: $this->normalizeJobResponse($job),
                message: 'Status retrieved',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            Log::debug($e);
            return $this->error(
                errors: null,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Normalize the job response into a consistent format for all vendors.
     *
     * The GenericPollStrategy already stores a normalized result structure:
     * {
     *   "assets": [{"id": "...", "url": "...", "duration": "...", "thumbnail": "..."}],
     *   "asset_urls": ["..."]
     * }
     *
     * This method maps that to the final frontend-friendly format with clean "videos" array.
     */
    private function normalizeJobResponse(AiJob $job): array
    {
        $normalized = [
            'job_id' => $job->queue_job_id,
            'feature' => $job->feature,
            'status' => $job->status,
            'progress' => $job->progress ?? 0,
            'result' => null,
            'error' => null,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ];

        if (!in_array($job->status, ['completed', 'failed'])) {
            return $normalized;
        }

        $rawResult = $job->result;

        if ($job->status === 'failed') {
            $normalized['error'] = $rawResult['error'] ?? 'Unknown error';
            return $normalized;
        }

        // The GenericPollStrategy stores assets with id, url, duration, thumbnail
        // already extracted via configurable paths. Just rename to "videos" for the frontend.
        $assets = $rawResult['assets'] ?? [];
        $videos = [];

        foreach ($assets as $asset) {
            $video = [
                'id' => $asset['id'] ?? null,
                'url' => $asset['url'] ?? null,
                'duration' => $asset['duration'] ?? null,
            ];

            // Thumbnail is now extracted by GenericPollStrategy using thumbnail_path config
            if (isset($asset['thumbnail'])) {
                $video['thumbnail'] = $asset['thumbnail'];
            }

            $videos[] = $video;
        }

        $normalized['result'] = [
            'videos' => $videos,
        ];

        return $normalized;
    }
}
