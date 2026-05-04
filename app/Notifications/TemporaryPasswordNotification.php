<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a user when an admin creates their account with a temporary
 * password. Goes out as a SEPARATE email from the verification code so
 * an attacker who somehow gets one mail still can't log in without the
 * other.
 *
 * On successful first login with this temp password, the API forces the
 * user through /auth/password/change-initial before issuing a real
 * sanctum token.
 */
class TemporaryPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $temporaryPassword)
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
            ->subject("Your temporary password for {$appName}")
            ->greeting('Hello!')
            ->line("An account has been created for you on {$appName}.")
            ->line('Your temporary password is:')
            ->line("**{$this->temporaryPassword}**")
            ->line('You will be asked to choose a new password the first time you log in.')
            ->line('A separate email contains your verification code — you will need to verify your email before logging in.')
            ->line('If you did not expect this account, please contact your administrator.')
            ->salutation("Thanks,\n{$appName}");
    }
}
