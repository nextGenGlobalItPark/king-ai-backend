<?php

namespace App\Listeners;

use App\Events\CategoryEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Jobs\SendPushNotification;

class CategoryEventPushNotification implements ShouldQueue
{
    public string $queue = 'notifications';
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CategoryEvent $event): void
    {
        $title = 'Category Updated!';
        $body = "";
        $data = [
            'type' => 'template',
            'action' => $event->action,
            'category_id' => $event->category->id,
        ];

        // Queue the send job (with delay if needed)
        SendPushNotification::dispatch($title, $body, $data);
    }
}
