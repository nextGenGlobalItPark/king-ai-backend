<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FCMPushService implements PushNotificationServiceInterface
{
    private Messaging $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(storage_path('app/vidio-king-ai-firebase.json'));
        $this->messaging = $factory->createMessaging();
    }

    /**
     * @throws MessagingException
     * @throws FirebaseException
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        if (empty($tokens)) {
            return;
        }

        // Batch if >500 (FCM limit ~500)
        $chunks = array_chunk($tokens, 500);
        foreach ($chunks as $chunk) {
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $report = $this->messaging->sendMulticast($message, $chunk);

            // Handle failures (e.g., invalid tokens)
            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    // $failure->target() returns a MessageTarget object in kreait/firebase-php v7+,
                    // not a raw string. Cast explicitly so logging and the DB comparison both
                    // see a string consistently across PDO drivers.
                    $token = (string) $failure->target();

                    Log::warning('Invalid FCM token', [
                        'token' => substr($token, 0, 10) . '...',
                        'error' => $failure->error()?->getMessage(),
                    ]);

                    // Update DB: Mark inactive
                    DB::table('devices')->where('token', $token)->update(['is_active' => false]);
                }
            }
        }
    }
}