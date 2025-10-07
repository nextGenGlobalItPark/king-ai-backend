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

class AiPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 60;  // Max 60 polls (e.g., 10 min at 10s intervals)
    public $backoff = 10;  // Fixed 10s backoff
    public $timeout = 30;  // Quick timeout for polls

    public function __construct(public AiJob $aiJob) {}

    public function handle(AiGenerationService $service): void
    {
        if ($this->aiJob->status !== 'processing') {
            return;  // Already handled elsewhere
        }

        try {
            $statusData = $service->pollStatus(
                $this->aiJob->task_id,
                $this->aiJob->data_source_id,
                $this->aiJob->feature
            );

            if ($statusData['status'] === 'completed') {
                $this->aiJob->update([
                    'status' => 'completed',
                    'result' => $statusData['result']
                ]);

                Log::info('AI Polling completed', ['job_id' => $this->aiJob->id]);

                // Notify user (e.g., via event, WebSocket, or email)
                // event(new AiJobCompleted($this->aiJob));
                return;  // Job succeeds
            } elseif ($statusData['status'] === 'failed') {
                $this->aiJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $statusData['error']]
                ]);

                Log::error('AI Polling detected failure', [
                    'job_id' => $this->aiJob->id,
                    'error' => $statusData['error']
                ]);

                // Notify
                // event(new AiJobFailed($this->aiJob));
                return;  // Job succeeds (handled)
            } else {
                // Still processing: release for retry
                $this->release(now()->addSeconds(10));
            }
        } catch (\Exception $e) {
            Log::warning('AI Polling error (retrying)', [
                'job_id' => $this->aiJob->id,
                'error' => $e->getMessage()
            ]);

            $this->release(now()->addSeconds(10));  // Retry on transient errors
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->aiJob->update([
            'status' => 'failed',
            'result' => ['error' => 'Polling timed out or max retries exceeded: ' . $exception->getMessage()]
        ]);

        Log::error('AI Polling job failed after retries', [
            'job_id' => $this->aiJob->id,
            'error' => $exception->getMessage()
        ]);

        // Notify admin/user
        // event(new AiJobFailed($this->aiJob));
    }
}