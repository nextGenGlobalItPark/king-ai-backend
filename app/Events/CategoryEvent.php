<?php

namespace App\Events;

use App\Models\TemplateCategory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CategoryEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public TemplateCategory $category;
    public string $action;

    /**
     * Create a new event instance.
     */
    public function __construct(TemplateCategory $category, string $action)
    {
        $this->category = $category;
        $this->action = $action;
    }
}
