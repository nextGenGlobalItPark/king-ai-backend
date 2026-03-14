<?php

namespace App\Listeners;

use App\Events\AiJobCompletedEvent;
use App\Jobs\SendPushNotification;
use App\Models\Device;
use App\Services\PushNotificationServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class AiJobCompletedPushNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(AiJobCompletedEvent $event): void
    {
        Log::debug("AiJobCompletedPushNotification");
        $aiJob = $event->aiJob;

        // Only notify the user who requested the generation
        if (!$aiJob->user_id) {
            return;
        }

        $isSuccess = $aiJob->status === 'completed';

        $title = $isSuccess ? 'Your asset is ready!' : 'Generation failed';
        $body = $isSuccess
            ? "Your {$aiJob->feature} generation has completed."
            : "Your {$aiJob->feature} generation failed. Please try again.";

        $data = [
            'type' => 'ai_job',
            'action' => $aiJob->status,
            'job_id' => $aiJob->queue_job_id,
            'feature' => $aiJob->feature,
        ];

        Log::debug("AiJobCompletedPushNotification: " . json_encode($data));

        // Get only this user's active device tokens
        $tokens = Device::where('user_id', $aiJob->user_id)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (!empty($tokens)) {
            // Use the push service directly for this user's devices
            app(PushNotificationServiceInterface::class)
                ->sendToTokens($tokens, $title, $body, $data);
        }
    }
}