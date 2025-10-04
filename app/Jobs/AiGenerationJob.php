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
use Illuminate\Support\Str;

class AiGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;  // Retry failed generations up to 3 times
    public $backoff = [10, 30, 60];  // Exponential backoff
    public $timeout = 300;  // 5 min timeout

    public ?int $aiJobId = null;
    public function __construct(
        public string $feature,
        public array $input,
        public ?int $userId = null,
        public ?string $vendor = null,
        public string $jobId
    ) {}

    public function handle(AiGenerationService $service): void
    {
        try {
            Log::debug("AI GenerationJob started");
            $vendor = Config::get('ai.default_vendor', 'vidu');
            $dataSource = DataSource::query()->whereLike('name', $vendor)->first();
            $dataSourceId = $dataSource->id;
            // Create or update job record
            Log::debug($dataSourceId);
            Log::debug("Job ID: ".$this->jobId);
            $aiJob = AiJob::updateOrCreate(
                ['queue_job_id' => $this->jobId],  // Use job UUID if available
                [
                    'task_id' => null,
                    'feature' => $this->feature,
                    'data_source_id' => $dataSourceId,
                    'input' => $this->input,
                    'user_id' => $this->userId,
                    'status' => 'processing'
                ]
            );
            Log::debug($aiJob);
            $this->aiJobId = $aiJob->id;
            Log::debug($aiJob);
            $response = $service->generate($this->feature, $this->input);
            $taskId = $response['task_id'] ?? $response['id'] ?? uniqid('task_');  // Assume task_id in response

            $aiJob->update(['task_id' => $taskId]);
            Log::debug($aiJob);
            // Dispatch polling job
            AiPollingJob::dispatch($aiJob)
                ->delay(now()->addSeconds(10))  // Initial poll delay
                ->onQueue('ai_polling');

            Log::info('AI Generation queued for polling', ['job_id' => $aiJob->id, 'task_id' => $taskId]);
        } catch (\Exception $e) {
            if (isset($aiJob)) {  // Guard against unset $aiJob
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $e->getMessage()]
                ]);
            }

            Log::error('AI Generation failed', [
                'job_id' => $this->aiJobId ?? null,
                'queue_job_id' => $queueJobId ?? null,
                'error' => $e->getMessage()
            ]);

            // Optionally notify user via event or email
            // event(new AiJobFailed($aiJob));
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->aiJobId) {
            $aiJob = AiJob::find($this->aiJobId);
            if ($aiJob) {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $exception->getMessage()]
                ]);
            }
        }

        Log::error('AI Generation job failed after retries', [
            'queue_job_id' => $this->job->getJobId(),
            'error' => $exception->getMessage()
        ]);
    }
}