<?php

namespace App\Http\Controllers;

use App\Jobs\AiGenerationJob;
use App\Models\AiFeature;
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
            $request->validate(['input' => 'required|array']);  // Basic; detailed validation in service
            $input = $request->input('input', []);

            $featureObj = AiFeature::with('dataSource')
                ->where('name', $feature)
                ->where('is_active', true)
                ->firstOrFail();
            $dataSourceId = $featureObj->data_source_id;
            $uuid = Str::uuid()->toString();
            $jobInstance = new AiGenerationJob($feature, $input, auth()->id(), $dataSourceId, $uuid);
            $jobInstance->uuid = $uuid;  // Set UUID manually for immediate access

            dispatch($jobInstance)->onQueue('ai_generation');

            return $this->success(
                data: ['job_id' => $jobInstance->uuid, 'vendor' => $dataSourceId],
                message: 'Generation queued',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Get job status.
     */
    public function status(string $queueJobId): JsonResponse
    {
        try {
            $job = AiJob::where('queue_job_id', $queueJobId)->firstOrFail();
            return $this->success(
                data: $job->load('user'),
                message: 'Status retrieved',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            Log::debug($e);
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}