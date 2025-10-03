<?php

namespace App\Http\Controllers;

use App\Jobs\AiGenerationJob;
use App\Models\AiJob;
use App\Models\Template;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

            $job = AiGenerationJob::dispatch(
                $feature,
                $input,
                auth()->id()
            )->onQueue('ai_generation');

            return $this->success(
                data: ['job_id' => $job->id],
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
    public function generateWithTemplate(Request $request, string $templateId): JsonResponse
    {
        $template = Template::with('aiFeature')->findOrFail($templateId);

        // Ensure template is linked to a valid, active AI feature
        if (!$template->aiFeature || !$template->aiFeature->is_active) {
            throw new InvalidArgumentException('Invalid or inactive template.');
        }

        $featureName = $template->aiFeature->name;

        // Determine vendor and fetch config for schema validation
        $vendor = $request->header('X-AI-Vendor') ?? Config::get('ai.default_vendor', 'vidu');
        $config = $template->aiFeature->configs()
            ->where('vendor_name', $vendor)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        if (!$config) {
            throw new InvalidArgumentException("No active config found for vendor '{$vendor}' on feature '{$featureName}'.");
        }

        // Validate input against the config's vendor-specific standard_schema
        $standardSchema = $config->standard_schema;
        $validator = \Illuminate\Support\Facades\Validator::make($request->input('input', []), [
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
        $job = AiGenerationJob::dispatch(
            $featureName,
            $input,
            auth()->id(),
            $vendor
        )->onQueue('ai_generation');

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $job->id,
                'template_used' => $template->id,
                'feature_used' => $featureName,
                'vendor_used' => $vendor,
            ],
            'message' => 'Template-based generation queued'
        ]);
    }

    /**
     * Get job status.
     */
    public function status(string $jobId): JsonResponse
    {
        try {
            $job = AiJob::findOrFail($jobId);
            return $this->success(
                data: $job->load('user'),
                message: 'Status retrieved',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}