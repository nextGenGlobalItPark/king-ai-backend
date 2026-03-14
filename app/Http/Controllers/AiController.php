<?php

namespace App\Http\Controllers;

use App\Jobs\AiGenerationJob;
use App\Models\AiFeature;
use App\Models\AiFeatureConfig;
use App\Models\AiJob;
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
            $request->validate(['input' => 'required|array']);
            $input = $request->input('input', []);

            $featureObj = AiFeature::with('dataSource')
                ->where('name', $feature)
                ->where('is_active', true)
                ->firstOrFail();
            $dataSourceId = $featureObj->data_source_id;
            $uuid = Str::uuid()->toString();
            $jobInstance = new AiGenerationJob($feature, $input, auth()->id(), $dataSourceId, $uuid);

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
     *       {"id": "...", "url": "...", "duration": "5.0"}
     *     ]
     *   },
     *   "error": null,
     *   "created_at": "...",
     *   "updated_at": "..."
     * }
     */
    public function status(string $queueJobId): JsonResponse
    {
        try {
            $job = AiJob::where('queue_job_id', $queueJobId)
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
     * The raw result stored in ai_jobs varies by vendor (Kling returns assets/asset_urls,
     * Vidu returns creations/videos, etc.). This method maps them to a standard structure
     * so the frontend never needs to know which vendor generated the content.
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

        // If not completed/failed, return early — no result to normalize
        if (!in_array($job->status, ['completed', 'failed'])) {
            return $normalized;
        }

        $rawResult = $job->result;

        // Handle failed jobs
        if ($job->status === 'failed') {
            $normalized['error'] = $rawResult['error'] ?? 'Unknown error';
            return $normalized;
        }

        // Handle completed jobs — normalize the result
        // The raw result from poll strategies has: assets[], asset_urls[], and optionally creations[]
        // We normalize to a standard "videos" array with id, url, duration
        $assets = $rawResult['assets'] ?? [];
        $videos = [];

        foreach ($assets as $asset) {
            $video = [
                'id' => $asset['id'] ?? null,
                'url' => $asset['url'] ?? null,
                'duration' => $asset['duration'] ?? null,
            ];

            // Include thumbnail if present (some vendors provide it)
            if (isset($asset['thumbnail']) || isset($asset['cover_url'])) {
                $video['thumbnail'] = $asset['thumbnail'] ?? $asset['cover_url'] ?? null;
            }

            $videos[] = $video;
        }

        $normalized['result'] = [
            'videos' => $videos,
        ];

        return $normalized;
    }
}