<?php

namespace App\Events;

use App\Models\Template;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TemplateEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Template $template;
    public string $action;

    /**
     * Create a new event instance.
     */
    public function __construct(Template $template, string $action)
    {
        $this->template = $template;
        $this->action = $action;
    }
}
