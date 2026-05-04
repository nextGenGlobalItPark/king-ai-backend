<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a user requests a password reset. Contains a 6-digit code that
 * the user submits along with their new password to set it.
 */
class PasswordResetNotification extends Notification implements ShouldQueue
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
            ->subject("Your {$appName} password reset code")
            ->greeting('Hello!')
            ->line("Someone (hopefully you) requested a password reset on {$appName}.")
            ->line('Your reset code is:')
            ->line("**{$this->code}**")
            ->line('This code will expire in 15 minutes.')
            ->line('If you did not request a reset, you can safely ignore this email — your password will not change.')
            ->salutation("Thanks,\n{$appName}");
    }
}
