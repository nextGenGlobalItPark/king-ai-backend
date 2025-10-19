<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\AiGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class AiPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $backoff = [10, 20, 40];  // Exponential: 10s, 20s, 40s...
    public int $tries = 30;  // Reduced to ~15-20 min max; adjust per service
    public int $timeout = 30;

    public function __construct(public AiJob $aiJob) {}

    public function handle(AiGenerationService $service): void
    {
        $aiJob = $this->aiJob->fresh();  // Always fresh for status/progress
        if ($aiJob->status !== 'processing') {
            Log::info('Polling skipped: status changed', ['job_id' => $aiJob->id, 'status' => $aiJob->status]);
            return;
        }

        try {
            $statusData = $service->pollStatus(
                $aiJob->task_id,
                $aiJob->data_source_id,
                $aiJob->feature
            // No $generateEndpoint; fetched internally
            );

            Log::debug('Poll result', [
                'job_id' => $aiJob->id,
                'status' => $statusData['status'],
                'progress' => $statusData['progress'] ?? 'N/A'
            ]);

            if ($statusData['status'] === 'complete') {
                $aiJob->update([
                    'status' => 'completed',
                    'result' => $statusData['result'],
                    'progress' => 100,  // Or from response
                ]);
                Log::info('AI job completed', ['job_id' => $aiJob->id]);
                // event(new AiJobCompleted($aiJob));  // Uncomment for notifications
                return;
            } elseif ($statusData['status'] === 'failed') {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $statusData['error']],
                    'progress' => 0,
                ]);
                Log::error('AI job failed via poll', ['job_id' => $aiJob->id, 'error' => $statusData['error']]);
                // event(new AiJobFailed($aiJob));
                return;
            } else {
                // Still processing: update progress if available, then re-queue
                $updateData = ['progress' => $statusData['progress'] ?? null];
                if ($aiJob->progress != $updateData['progress']) {  // Use != for null-safe
                    $aiJob->update($updateData);
                    Log::info('Progress updated', ['job_id' => $aiJob->id, 'progress' => $updateData['progress']]);
                }
                // Use attempts-based backoff (0-indexed)
                $backoffSeconds = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60;
                $this->release(Carbon::now()->addSeconds($backoffSeconds));
            }
        } catch (\Exception $e) {
            Log::warning('Polling error (retrying)', [
                'job_id' => $aiJob->id,
                'attempt' => $this->attempts(),  // Fixed: Use method
                'error' => $e->getMessage()
            ]);
            $this->release(now()->addSeconds(10));  // Short retry on errors
        }
    }

    public function failed(\Throwable $exception): void
    {
        $aiJob = $this->aiJob->fresh();
        $aiJob->update([
            'status' => 'failed',
            'result' => ['error' => 'Polling exhausted: ' . $exception->getMessage()],
            'progress' => 0,
        ]);

        Log::error('AI Polling exhausted', [
            'job_id' => $aiJob->id,
            'attempts' => $this->tries,
            'error' => $exception->getMessage()
        ]);
        // event(new AiJobFailed($aiJob));
    }
}