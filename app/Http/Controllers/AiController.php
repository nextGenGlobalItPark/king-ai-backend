<?php

namespace App\Http\Controllers;

use App\Jobs\AiGenerationJob;
use App\Models\AiJob;
use App\Models\DataSource;
use App\Models\Template;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

            // Dynamic vendor support
            $vendor = $request->query('vendor', Config::get('ai.default_vendor', 'vidu'));
            $dataSource = DataSource::query()->whereLike('name', $vendor)->firstOrFail();
            $dataSourceId = $dataSource->id;

            $uuid = Str::uuid()->toString();
            $jobInstance = new AiGenerationJob($feature, $input, auth()->id(), $dataSourceId, $uuid);
            $jobInstance->uuid = $uuid;  // Set UUID manually for immediate access

            dispatch($jobInstance)->onQueue('ai_generation');

            return $this->success(
                data: ['job_id' => $jobInstance->uuid, 'vendor' => $vendor],
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