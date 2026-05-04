<?php

namespace App\Jobs;

use App\Events\AiJobCompletedEvent;
use App\Models\AiJob;
use App\Services\AiGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class AiPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Only use tries for hard failure retries (e.g. exceptions), not for polling loops
    public int $tries = 3;
    public int $timeout = 60;

    private const MAX_DURATION_MINUTES = 20;
    private const POLL_INTERVALS = [10, 20, 40, 60]; // Progressive backoff in seconds

    public function __construct(public AiJob $aiJob) {}

    /**
     * @throws RequestException
     */
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

            if ($statusData['status'] === 'completed') {
                $aiJob->update([
                    'status' => 'completed',
                    'result' => $statusData['result'],
                    'progress' => 100.0,
                ]);
                Log::info('AI job completed', ['job_id' => $aiJob->id]);

                // Dispatch asset download — it will fire AiJobCompletedEvent after downloading
                AssetDownloadJob::dispatch($aiJob)->onQueue('ai_polling');
                return;
            }

            if ($statusData['status'] === 'failed') {
                $aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $statusData['error']],
                    'progress' => 0.0,
                ]);
                Log::error('AI job failed via poll', ['job_id' => $aiJob->id, 'error' => $statusData['error']]);
                event(new AiJobCompletedEvent($aiJob));
                return;
            }

            // Still processing: update progress and re-dispatch a new polling job
            $newProgress = $statusData['progress'] ?? null;
            if ($aiJob->progress != $newProgress) {
                $aiJob->update(['progress' => $newProgress]);
                Log::info('Progress updated', ['job_id' => $aiJob->id, 'progress' => $newProgress]);
            }

            // Calculate progressive backoff based on time elapsed.
            // Carbon 3 changed diffInSeconds() to return signed values by default —
            // now()->diffInSeconds($pastDate) returns a NEGATIVE number. Use the
            // second arg `true` (absolute) so the elapsed seconds are always positive
            // and the interval index actually advances over time.
            $elapsedSeconds = (int) now()->diffInSeconds($aiJob->created_at, true);
            $intervalIndex = max(0, min((int) ($elapsedSeconds / 60), count(self::POLL_INTERVALS) - 1));
            $nextDelay = self::POLL_INTERVALS[$intervalIndex];

            // Dispatch a fresh polling job instead of release() to avoid tries conflict
            self::dispatch($aiJob)
                ->delay(now()->addSeconds($nextDelay))
                ->onQueue('ai_polling');

        } catch (\Exception $e) {
            Log::warning('Polling error (will retry)', [
                'job_id' => $aiJob->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Let the tries mechanism handle genuine exceptions
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $aiJob = $this->aiJob->fresh();
        if ($aiJob && $aiJob->status === 'processing') {
            $aiJob->update([
                'status' => 'failed',
                'result' => ['error' => 'Polling failed: ' . $exception->getMessage()],
                'progress' => 0.0,
            ]);
        }

        Log::error('AI Polling job failed after retries', [
            'job_id' => $aiJob->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
