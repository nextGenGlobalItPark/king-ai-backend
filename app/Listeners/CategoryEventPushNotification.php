<?php

namespace App\Listeners;

use App\Events\CategoryEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Jobs\SendPushNotification;

class CategoryEventPushNotification implements ShouldQueue
{
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
            'template_id' => $event->template->id,
            'category_id' => $event->template->template_category_id,
        ];

        // Queue the send job (with delay if needed)
        SendPushNotification::dispatch($title, $body, $data)->onQueue('notifications');
    }
}
