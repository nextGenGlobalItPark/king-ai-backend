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

    public array $backoff = [10, 20, 40];
    public int $tries = 30;  // ~10-15min max
    public int $timeout = 600;  // 10min total job timeout
    private const MAX_DURATION_MINUTES = 20;

    public function __construct(public AiJob $aiJob) {}

    public function handle(AiGenerationService $service): void
    {
        $aiJob = $this->aiJob->fresh();
        if ($aiJob->status !== 'processing') {
            Log::info('Polling skipped: status changed', ['job_id' => $aiJob->id, 'status' => $aiJob->status]);
            return;
        }

        // Max duration check
        if ($aiJob->created_at->addMinutes(self::MAX_DURATION_MINUTES)->lt(now())) {
            $aiJob->update([
                'status' => 'failed',
                'result' => ['error' => 'Generation timed out after ' . self::MAX_DURATION_MINUTES . ' minutes'],
                'progress' => 0.0,
            ]);
            Log::error('Polling timed out by duration', ['job_id' => $aiJob->id]);
            return;
        }

        try {
            $statusData = $service->pollStatus(
                $aiJob->task_id,
                $aiJob->data_source_id,
                $aiJob->feature
            );

            Log::debug('Poll result', [
                'job_id' => $aiJob->id,
                'status' => $statusData['status'],
                'progress' => $statusData['progress'] ?? 'N/A',
            ]);

            if ($statusData['status'] === 'completed') {  // Note: Standardized to 'completed'
                $aiJob->update([
                    'status' => 'completed',
                    'result' => $statusData['result'],
                    'progress' => 100.0,
                ]);
                Log::info('AI job completed', ['job_id' => $aiJob->id]);
                return;
            } elseif ($statusData['status'] === 'failed') {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $statusData['error']],
                    'progress' => 0.0,
                ]);
                Log::error('AI job failed via poll', ['job_id' => $aiJob->id, 'error' => $statusData['error']]);
                return;
            } else {
                // Processing: Update progress if changed
                $newProgress = $statusData['progress'] ?? null;
                if ($aiJob->progress != $newProgress) {
                    $aiJob->update(['progress' => $newProgress]);
                    Log::info('Progress updated', ['job_id' => $aiJob->id, 'progress' => $newProgress]);
                }

                // Backoff retry
                $attemptIndex = min($this->attempts() - 1, count($this->backoff) - 1);
                $backoffSeconds = $this->backoff[$attemptIndex] ?? 60;
                $this->release(Carbon::now()->addSeconds($backoffSeconds));
            }
        } catch (\Exception $e) {
            Log::warning('Polling error (retrying)', [
                'job_id' => $aiJob->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            $this->release(now()->addSeconds(10));
        }
    }

    public function failed(\Throwable $exception): void
    {
        $aiJob = $this->aiJob->fresh();
        $aiJob->update([
            'status' => 'failed',
            'result' => ['error' => 'Polling exhausted: ' . $exception->getMessage()],
            'progress' => 0.0,
        ]);

        Log::error('AI Polling exhausted', [
            'job_id' => $aiJob->id,
            'attempts' => $this->tries,
            'error' => $exception->getMessage(),
        ]);
    }
}