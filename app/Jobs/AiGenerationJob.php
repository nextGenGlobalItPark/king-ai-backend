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
use Illuminate\Support\Facades\Log;

class AiGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;  // Retry failed generations up to 3 times
    public $backoff = [10, 30, 60];  // Exponential backoff
    public $timeout = 300;  // 5 min timeout

    public function __construct(
        public string $feature,
        public array $input,
        public ?int $userId = null,
        public ?string $vendor = null
    ) {}

    public function handle(AiGenerationService $service): void
    {
        try {
            $dataSource = DataSource::query()->whereLike('name', $this->vendor)->first();
            $dataSourceId = $dataSource->id;
            // Create or update job record
            $aiJob = AiJob::updateOrCreate(
                ['id' => $this->job->uuid ?? null],  // Use job UUID if available
                [
                    'task_id' => null,
                    'feature' => $this->feature,
                    'data_source_id' => $dataSourceId,
                    'input' => $this->input,
                    'user_id' => $this->userId,
                    'status' => 'processing'
                ]
            );

            $response = $service->generate($this->feature, $this->input);
            $taskId = $response['task_id'] ?? $response['id'] ?? uniqid('task_');  // Assume task_id in response

            $aiJob->update(['task_id' => $taskId]);

            // Dispatch polling job
            AiPollingJob::dispatch($aiJob)
                ->delay(now()->addSeconds(10))  // Initial poll delay
                ->onQueue('ai_polling');

            Log::info('AI Generation queued for polling', ['job_id' => $aiJob->id, 'task_id' => $taskId]);
        } catch (\Exception $e) {
            $aiJob->update([
                'status' => 'failed',
                'result' => ['error' => $e->getMessage()]
            ]);

            Log::error('AI Generation failed', [
                'job_id' => $aiJob->id,
                'error' => $e->getMessage()
            ]);

            // Optionally notify user via event or email
            // event(new AiJobFailed($aiJob));
        }
    }

    public function failed(\Throwable $exception): void
    {
        $aiJob = AiJob::where('feature', $this->feature)
            ->where('user_id', $this->userId)
            ->where('status', 'processing')
            ->first();

        if ($aiJob) {
            $aiJob->update([
                'status' => 'failed',
                'result' => ['error' => $exception->getMessage()]
            ]);

            Log::error('AI Generation job failed after retries', [
                'job_id' => $aiJob->id,
                'error' => $exception->getMessage()
            ]);
        }
    }
}