<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\PushNotificationServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; // Retry on failure
    public array $backoff = [10, 30, 60]; // Exponential backoff

    public function __construct(
        public string $title,
        public string $body,
        public array $data = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PushNotificationServiceInterface $pushService): void
    {
        $devices = Device::where('is_active', true)->cursor();

        foreach ($devices as $device) {
            // Batch in service, but process per-device for logging
            $pushService->sendToTokens([$device->token], $this->title, $this->body, $this->data);

            Log::info('Push sent to device', [
                'device_id' => $device->id,
                'token' => substr($device->token, 0, 10) . '...', // Partial for security
                'data' => $this->data,
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Push notification failed', ['error' => $exception->getMessage()]);
    }
}
