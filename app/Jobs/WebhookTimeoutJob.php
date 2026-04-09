<?php

namespace App\Jobs;

use App\Events\AiJobCompletedEvent;
use App\Models\AiJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for callback-mode jobs.
 *
 * When a job uses completion_mode='callback', the system relies on the vendor
 * sending a webhook when the task completes. This timeout job is dispatched
 * with a delay (default 20 minutes) as a safety net — if the vendor never
 * calls back (network issue, vendor bug, etc.), this job marks it as failed
 * so it doesn't hang forever in 'processing' state.
 *
 * If the webhook arrives before this job runs, the job status will already
 * be 'completed' or 'failed', and this job simply exits.
 */
class WebhookTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    private const DEFAULT_TIMEOUT_MINUTES = 20;

    public function __construct(
        public AiJob $aiJob,
        public int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES,
    ) {}

    public function handle(): void
    {
        $aiJob = $this->aiJob->fresh();

        // Job already completed or failed — webhook arrived in time
        if ($aiJob->status !== 'processing') {
            Log::debug('WebhookTimeoutJob: job already resolved, skipping', [
                'job_id' => $aiJob->id,
                'status' => $aiJob->status,
            ]);
            return;
        }

        // Still processing after timeout — mark as failed
        $aiJob->update([
            'status' => 'failed',
            'result' => [
                'error' => "No callback received within {$this->timeoutMinutes} minutes. "
                    . 'The vendor may have failed to send the webhook, or the callback URL was unreachable.',
            ],
            'progress' => 0.0,
        ]);

        Log::error('WebhookTimeoutJob: job timed out waiting for callback', [
            'job_id' => $aiJob->id,
            'queue_job_id' => $aiJob->queue_job_id,
            'feature' => $aiJob->feature,
            'timeout_minutes' => $this->timeoutMinutes,
        ]);

        event(new AiJobCompletedEvent($aiJob));
    }
}
