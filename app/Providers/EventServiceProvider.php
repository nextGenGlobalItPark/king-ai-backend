<?php

namespace App\Providers;

use App\Events\CategoryEvent;
use App\Events\TemplateEvent;
use App\Listeners\CategoryEventPushNotification;
use App\Listeners\TemplateEventPushNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected array $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        TemplateEvent::class => [
            TemplateEventPushNotification::class,
        ],
        CategoryEvent::class => [
            CategoryEventPushNotification::class,
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}