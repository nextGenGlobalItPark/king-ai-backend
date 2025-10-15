<?php

namespace App\Listeners;

use App\Events\TemplateEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Jobs\SendPushNotification;

class TemplateEventPushNotification implements ShouldQueue
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
    public function handle(TemplateEvent $event): void
    {
        $title = 'Template Updated!';
        $body = "A new '{$event->template->name}' template is available.";
        $data = [
            'type' => 'template',
            'action' => $event->action,
            'template_id' => $event->template->id,
            'category_id' => $event->template->template_category_id,
        ];

        // Queue the send job (with delay if needed)
        SendPushNotification::dispatch($title, $body, $data);
    }
}
