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
        // Collect tokens in batches of 500 (FCM limit) instead of one-by-one
        $tokenBatch = [];

        Device::where('is_active', true)
            ->select(['id', 'token'])
            ->chunkById(500, function ($devices) use ($pushService, &$tokenBatch) {
                $tokens = $devices->pluck('token')->toArray();

                if (!empty($tokens)) {
                    $pushService->sendToTokens($tokens, $this->title, $this->body, $this->data);

                    Log::info('Push batch sent', [
                        'device_count' => count($tokens),
                        'data' => $this->data,
                    ]);
                }
            });
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Push notification failed', ['error' => $exception->getMessage()]);
    }
}
