<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends the 6-digit email verification code to the user.
 *
 * Implements ShouldQueue so SMTP delivery doesn't block the HTTP response.
 * On the API register endpoint, we want to return 200 to the client
 * immediately and let the queue worker actually push the mail. If you have
 * the queue configured to "sync" (default in dev), it falls back to inline
 * sending automatically, so this works in development too.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'Vidio King');

        return (new MailMessage)
            ->subject("Your {$appName} verification code")
            ->greeting('Hello!')
            ->line("Your verification code for {$appName} is:")
            ->line("**{$this->code}**")
            ->line('This code will expire in 15 minutes.')
            ->line('If you did not request this code, you can safely ignore this email.')
            ->salutation("Thanks,\n{$appName}");
    }
}
