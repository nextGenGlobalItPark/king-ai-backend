<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\DataSource;
use App\Services\AiGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
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
        public ?int $dataSourceId = null,  // Prioritize this over vendor string
        public string $jobId
    ) { }

    public function handle(AiGenerationService $service): void
    {
        $aiJob = null;
        try {
            Log::debug("AI GenerationJob started", ['job_id' => $this->jobId]);

            $dataSource = DataSource::find($this->dataSourceId);
            if (!$dataSource) {
                throw new \InvalidArgumentException("No data source found for ID '{$this->dataSourceId}'");
            }
            $dataSourceId = $dataSource->id;

            Log::debug("AI GenerationJob started", ['job_id' => $this->jobId]);
            Log::debug($this->feature);
            // Create or update job record
            $aiJob = AiJob::updateOrCreate(
                ['queue_job_id' => $this->jobId],
                [
                    'task_id' => null,
                    'feature' => $this->feature,
                    'data_source_id' => $dataSourceId,
                    'input' => $this->input,
                    'user_id' => $this->userId,
                    'status' => 'processing',
                    'progress' => 0.0,
                ]
            );
            $this->aiJobId = $aiJob->id;
            Log::debug('AiJob created/updated', ['ai_job_id' => $aiJob->id]);

            $response = $service->generate($this->feature, $this->input);
            $taskId = $response['task_id'] ?? $response['id'] ?? uniqid('task_');

            $aiJob->update(['task_id' => $taskId]);
            Log::debug('Task ID set', ['task_id' => $taskId]);

            // Dispatch polling job
            AiPollingJob::dispatch($aiJob)
                ->delay(now()->addSeconds(10))
                ->onQueue('ai_polling');

            Log::info('AI Generation queued for polling', ['job_id' => $aiJob->id, 'task_id' => $taskId]);
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
            'queue_job_id' => $this->job->getJobId(),
            'error' => $exception->getMessage(),
        ]);
    }
}