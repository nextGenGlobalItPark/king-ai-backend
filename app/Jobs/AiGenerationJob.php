<?php

namespace App\Jobs;

use App\Models\AiFeature;
use App\Models\AiJob;
use App\Models\DataSource;
use App\Services\AiGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AiGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 300;

    public ?int $aiJobId = null;

    public function __construct(
        public string $feature,
        public array $input,
        public ?int $userId = null,
        public ?int $dataSourceId = null,
        public string $jobId,
        public ?int $templateId = null,
        public ?int $creditCost = null,
    ) { }

    public function handle(AiGenerationService $service): void
    {
        $aiJob = null;
        try {
            Log::debug("AI GenerationJob started", [
                'job_id' => $this->jobId,
                'feature' => $this->feature,
                'data_source_id' => $this->dataSourceId,
            ]);

            $dataSource = DataSource::find($this->dataSourceId);
            if (!$dataSource) {
                throw new \InvalidArgumentException("No data source found for ID '{$this->dataSourceId}'");
            }
            $dataSourceId = $dataSource->id;

            // Create or update job record
            $aiJob = AiJob::updateOrCreate(
                ['queue_job_id' => $this->jobId],
                [
                    'task_id' => null,
                    'feature' => $this->feature,
                    'data_source_id' => $dataSourceId,
                    'template_id' => $this->templateId,
                    'credit_cost' => $this->creditCost,
                    'input' => $this->input,
                    'user_id' => $this->userId,
                    'status' => 'processing',
                    'progress' => 0.0,
                ]
            );
            $this->aiJobId = $aiJob->id;
            Log::debug('AiJob created/updated', ['ai_job_id' => $aiJob->id]);

            // Determine completion mode BEFORE generation so we can inject callback URL
            $completionMode = $this->resolveCompletionMode($this->feature, $dataSourceId);

            // Pass jobId for callback URL injection when in callback mode
            $callbackJobId = ($completionMode === 'callback') ? $this->jobId : null;
            $response = $service->generate($this->feature, $this->input, $callbackJobId);
            $taskId = $response['task_id'] ?? $response['id'] ?? uniqid('task_');

            $aiJob->update(['task_id' => $taskId]);
            Log::debug('Task ID set', ['task_id' => $taskId]);

            if ($completionMode === 'callback') {
                // Callback mode: vendor will POST to our webhook when done.
                // Dispatch a safety-net timeout job in case the callback never arrives.
                $timeoutMinutes = 20;

                WebhookTimeoutJob::dispatch($aiJob, $timeoutMinutes)
                    ->delay(now()->addMinutes($timeoutMinutes))
                    ->onQueue('ai_polling'); // Reuse the polling queue for timeout checks

                Log::info('AI Generation using callback mode — waiting for vendor webhook', [
                    'job_id' => $aiJob->id,
                    'task_id' => $taskId,
                    'timeout_minutes' => $timeoutMinutes,
                ]);
            } else {
                // Polling mode: dispatch the polling job (existing behavior)
                AiPollingJob::dispatch($aiJob)
                    ->delay(now()->addSeconds(10))
                    ->onQueue('ai_polling');

                Log::info('AI Generation using polling mode', [
                    'job_id' => $aiJob->id,
                    'task_id' => $taskId,
                ]);
            }
        } catch (\Exception $e) {
            if ($aiJob) {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $e->getMessage()],
                    'progress' => 0.0,
                ]);
            }

            Log::error('AI Generation failed', [
                'job_id' => $this->aiJobId ?? null,
                'queue_job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;  // Re-throw for retry
        }
    }

    /**
     * Resolve the completion mode from the active feature config.
     *
     * Returns 'callback' or 'polling' (default).
     */
    private function resolveCompletionMode(string $featureName, int $dataSourceId): string
    {
        try {
            $feature = AiFeature::where('name', $featureName)
                ->where('is_active', true)
                ->first();

            if (!$feature) {
                return 'polling';
            }

            $config = $feature->configs()
                ->where('data_source_id', $dataSourceId)
                ->where('is_active', true)
                ->orderBy('priority')
                ->first();

            return $config?->completion_mode ?? 'polling';
        } catch (\Exception $e) {
            Log::warning('Failed to resolve completion mode, defaulting to polling', [
                'error' => $e->getMessage(),
            ]);
            return 'polling';
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->aiJobId) {
            $aiJob = AiJob::find($this->aiJobId);
            if ($aiJob) {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $exception->getMessage()],
                    'progress' => 0.0,
                ]);
            }
        }

        Log::error('AI Generation job failed after retries', [
            'queue_job_id' => $this->jobId,
            'error' => $exception->getMessage(),
        ]);
    }
}
