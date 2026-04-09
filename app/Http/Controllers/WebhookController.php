<?php

namespace App\Http\Controllers;

use App\Events\AiJobCompletedEvent;
use App\Jobs\AssetDownloadJob;
use App\Models\AiFeature;
use App\Models\AiJob;
use App\Services\PollStrategyResolver;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private PollStrategyResolver $strategyResolver,
    ) {}

    /**
     * Handle incoming vendor webhook/callback.
     *
     * This endpoint is called by AI vendors (Kling, etc.) when a generation
     * task completes or fails. It uses the same GenericPollStrategy::parseResponse()
     * as the polling path, so normalization logic is shared — no duplication.
     *
     * Route: POST /api/webhook/{jobId}
     * No auth middleware (vendor calls this), secured by UUID unpredictability
     * + optional webhook_secret signature verification.
     */
    public function handle(Request $request, string $jobId): JsonResponse
    {
        Log::info('Webhook received', [
            'job_id' => $jobId,
            'ip' => $request->ip(),
            'payload_keys' => array_keys($request->all()),
        ]);

        // Look up the job by queue_job_id (the UUID the vendor knows)
        $aiJob = AiJob::where('queue_job_id', $jobId)->first();

        if (!$aiJob) {
            Log::warning('Webhook received for unknown job', ['job_id' => $jobId]);
            return $this->error(
                errors: null,
                message: 'Job not found',
                status: Response::HTTP_NOT_FOUND
            );
        }

        // Skip if job is already in a terminal state
        if (in_array($aiJob->status, ['completed', 'failed'])) {
            Log::info('Webhook ignored: job already in terminal state', [
                'job_id' => $aiJob->id,
                'status' => $aiJob->status,
            ]);
            return $this->success(
                data: ['status' => $aiJob->status],
                message: 'Job already processed'
            );
        }

        // Load the feature config to get response_mapping
        $feature = AiFeature::where('name', $aiJob->feature)->first();
        if (!$feature) {
            Log::error('Webhook: feature not found', ['feature' => $aiJob->feature]);
            return $this->error(
                errors: null,
                message: 'Feature config not found',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $config = $feature->configs()
            ->where('data_source_id', $aiJob->data_source_id)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            Log::error('Webhook: no active config found', [
                'feature' => $aiJob->feature,
                'data_source_id' => $aiJob->data_source_id,
            ]);
            return $this->error(
                errors: null,
                message: 'Feature config not found',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Verify webhook signature if secret is configured
        if (!$this->verifySignature($request, $config)) {
            Log::warning('Webhook signature verification failed', [
                'job_id' => $jobId,
                'ip' => $request->ip(),
            ]);
            return $this->error(
                errors: null,
                message: 'Invalid signature',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        // Decode response mapping
        $responseMapping = $config->response_mapping;
        if (is_string($responseMapping)) {
            $responseMapping = json_decode($responseMapping, true) ?? [];
        }

        // Use the same GenericPollStrategy to parse the webhook payload
        // This is the key — same normalization code path as polling
        $strategy = $this->strategyResolver->resolve($aiJob->data_source_id, $responseMapping);

        try {
            $payload = $request->all();
            $statusData = $strategy->parseResponse($payload, $responseMapping);

            Log::info('Webhook parsed', [
                'job_id' => $aiJob->id,
                'status' => $statusData['status'],
            ]);

            if ($statusData['status'] === 'completed') {
                $aiJob->update([
                    'status' => 'completed',
                    'result' => $statusData['result'],
                    'progress' => 100.0,
                ]);
                Log::info('AI job completed via webhook', ['job_id' => $aiJob->id]);

                // Dispatch asset download — it will fire AiJobCompletedEvent after downloading
                AssetDownloadJob::dispatch($aiJob)->onQueue('ai_polling');

            } elseif ($statusData['status'] === 'failed') {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $statusData['error']],
                    'progress' => 0.0,
                ]);
                Log::error('AI job failed via webhook', [
                    'job_id' => $aiJob->id,
                    'error' => $statusData['error'],
                ]);
                event(new AiJobCompletedEvent($aiJob));

            } else {
                // Still processing — update progress if available
                $newProgress = $statusData['progress'] ?? null;
                if ($newProgress !== null && $aiJob->progress != $newProgress) {
                    $aiJob->update(['progress' => $newProgress]);
                }
                Log::info('Webhook received processing update', [
                    'job_id' => $aiJob->id,
                    'progress' => $newProgress,
                ]);
            }

            return $this->success(
                data: ['status' => $statusData['status']],
                message: 'Webhook processed'
            );

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'job_id' => $aiJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                errors: null,
                message: 'Failed to process webhook',
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Verify the webhook signature if a secret is configured.
     *
     * Different vendors use different signature schemes. This method
     * supports common patterns. If no secret is configured, verification
     * is skipped (relying on UUID unpredictability as the security layer).
     */
    private function verifySignature(Request $request, $config): bool
    {
        $secret = $config->webhook_secret;

        // No secret configured — skip verification
        if (empty($secret)) {
            return true;
        }

        // Check common signature headers
        // Kling uses a token-based verification in the payload or header
        $signatureHeader = $request->header('X-Webhook-Signature')
            ?? $request->header('X-Signature')
            ?? $request->header('Authorization');

        if (empty($signatureHeader)) {
            Log::warning('Webhook secret configured but no signature header found');
            return false;
        }

        // HMAC-SHA256 verification (most common pattern)
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Strip common prefixes like "sha256=" or "Bearer "
        $providedSignature = preg_replace('/^(sha256=|Bearer\s+)/i', '', $signatureHeader);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
