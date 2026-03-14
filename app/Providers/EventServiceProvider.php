<?php

namespace App\Providers;

use App\Events\AiJobCompletedEvent;
use App\Events\CategoryEvent;
use App\Events\TemplateEvent;
use App\Listeners\AiJobCompletedPushNotification;
use App\Listeners\CategoryEventPushNotification;
use App\Listeners\TemplateEventPushNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        TemplateEvent::class => [
            TemplateEventPushNotification::class,
        ],
        CategoryEvent::class => [
            CategoryEventPushNotification::class,
        ],
        AiJobCompletedEvent::class => [
            AiJobCompletedPushNotification::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}