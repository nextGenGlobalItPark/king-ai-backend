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

            $vendor = Config::get('ai.default_vendor', 'vidu');
            $dataSource = DataSource::query()->whereLike('name', $vendor)->first();
            $dataSourceId = $dataSource->id;

            $uuid = Str::uuid()->toString();
            $jobInstance = new AiGenerationJob($feature, $input, auth()->id(), $dataSourceId, $uuid);
            $jobInstance->uuid = $uuid;  // Set UUID manually for immediate access

            dispatch($jobInstance)->onQueue('ai_generation');

            return $this->success(
                data: ['job_id' => $jobInstance->uuid],
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
     * Generate AI content using a specific template (queues job).
     * Validates input against the template's linked AI feature config schema.
     */
    public function generateWithTemplate(Request $request, int $templateId): JsonResponse
    {
        $request->validate(['input' => 'required|array']);
        $template = Template::with('aiFeature')->findOrFail($templateId);

        // Ensure the template is linked to a valid, active AI feature
        if (!$template->aiFeature || !$template->aiFeature->is_active) {
            throw new InvalidArgumentException('Invalid or inactive template.');
        }

        $featureName = $template->aiFeature->name;

        // Determine vendor and fetch config for schema validation
        $vendor = Config::get('ai.default_vendor', 'vidu');
        $dataSourceId = DataSource::query()->whereLike('name', $vendor)->pluck('id');

        $config = $template->aiFeature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        if (!$config) {
            throw new InvalidArgumentException("No active config found for vendor '{$vendor}' on feature '{$featureName}'.");
        }

        // Validate input against the config's vendor-specific standard_schema
        $standardSchema = $config->standard_schema;
        $validator = Validator::make($request->input('input', []), [
            ...array_fill_keys($standardSchema['required'] ?? [], 'required|string'),  // Assume string; customize as needed
            ...array_map(fn($field) => [$field => 'sometimes|string'], $standardSchema['optional'] ?? [])
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                "Invalid input for '{$vendor}' ({$featureName}): " . json_encode($validator->errors())
            );
        }

        $input = $request->input('input', []);

        // Optionally enrich input with template metadata if needed (e.g., for prompt appending)
        // $input['template_id'] = $templateId;  // Uncomment if service mapping uses it

        // Dispatch job using the template's linked feature
        $uuid = Str::uuid()->toString();
        $jobInstance = new AiGenerationJob($featureName, $input, auth()->id(), $vendor, $uuid);
        $jobInstance->uuid = $uuid;

        dispatch($jobInstance)->onQueue('ai_generation');

        return $this->success(
            data: [
                'job_id' => $jobInstance->uuid,
                'template_used' => $template->id,
                'feature_used' => $featureName,
                'vendor_used' => $vendor,
            ],message: 'Template-based generation queued'
        );
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