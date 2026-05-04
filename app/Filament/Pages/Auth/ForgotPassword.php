<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\PasswordResetService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;

/**
 * Forgot password flow for the Filament admin panel.
 *
 * Implemented as a single SimplePage with two stages tracked by the
 * $stage property:
 *   1. 'request' — user enters email; on submit, we send the code (via
 *      PasswordResetService) and advance to stage 2. Either way we show
 *      the same success message to prevent enumeration.
 *   2. 'reset'   — user enters code + new password; on submit, we verify
 *      and reset, then redirect to login.
 *
 * SimplePage is the right base class here because the user is
 * unauthenticated (they're locked out by definition). It gives us the
 * stripped-down "auth screen" layout shared with login/register, plus
 * methods like hasLogo() that the layout's blade template expects.
 *
 * The route is registered in AdminPanelProvider via ->routes(), which
 * runs OUTSIDE auth middleware.
 */
class ForgotPassword extends SimplePage implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.auth.forgot-password';

    public ?array $data = [];

    /** Either 'request' (enter email) or 'reset' (enter code + new pw). */
    public string $stage = 'request';

    /** Email captured at stage 1 — preserved into stage 2 so the user doesn't retype. */
    public ?string $email = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        if ($this->stage === 'reset') {
            return $form
                ->schema([
                    TextInput::make('code')
                        ->label('6-digit code from your email')
                        ->placeholder('123456')
                        ->required()
                        ->numeric()
                        ->length(6)
                        ->autocomplete('one-time-code')
                        ->extraInputAttributes(['inputmode' => 'numeric', 'autofocus' => true]),
                    TextInput::make('new_password')
                        ->label('New password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(User::PASSWORD_MIN_LENGTH),
                    TextInput::make('new_password_confirmation')
                        ->label('Confirm new password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->same('new_password'),
                ])
                ->statePath('data');
        }

        return $form
            ->schema([
                TextInput::make('email')
                    ->label('Your email address')
                    ->email()
                    ->required()
                    ->maxLength(User::EMAIL_MAX_LENGTH)
                    ->extraInputAttributes(['autofocus' => true]),
            ])
            ->statePath('data');
    }

    /**
     * Stage 1 submit: request a reset code. We always advance to stage 2
     * regardless of whether the email exists (enumeration prevention) —
     * the user is told "if your email is registered, a code was sent".
     */
    public function requestCode(PasswordResetService $resetter): void
    {
        $data = $this->form->getState();
        $email = $data['email'];

        $user = User::where('email', $email)->first();

        // Match the API's enumeration-prevention behavior. We do dispatch
        // the email when the user exists AND is verified — same gate as
        // PasswordResetController.
        if ($user && $user->hasVerifiedEmail()) {
            try {
                $resetter->sendCode($user);
            } catch (\RuntimeException) {
                // Cooldown — already a code in flight. That's fine, the user
                // will use the existing one.
            }
        }

        $this->email = $email;
        $this->stage = 'reset';
        $this->data = [];
        $this->form->fill();

        Notification::make()
            ->title('Code sent')
            ->body("If {$email} is registered, a 6-digit code has been emailed.")
            ->success()
            ->send();
    }

    /**
     * Stage 2 submit: verify code + apply new password. On success,
     * redirect to login. The user re-logs in with the new password —
     * we deliberately don't auto-login here because it would skip the
     * 2FA gate that lives in the login flow.
     */
    public function resetPassword(PasswordResetService $resetter): void
    {
        $data = $this->form->getState();

        $user = User::where('email', $this->email)->first();

        if (! $user) {
            Notification::make()
                ->title('Invalid code')
                ->danger()
                ->send();
            return;
        }

        $result = $resetter->verifyAndReset(
            $user,
            $data['code'],
            $data['new_password']
        );

        switch ($result) {
            case 'reset':
                Notification::make()
                    ->title('Password updated')
                    ->body('Please log in with your new password.')
                    ->success()
                    ->send();
                $this->redirect(filament()->getLoginUrl());
                return;

            case 'expired':
                Notification::make()
                    ->title('Code expired')
                    ->body('Please request a new code.')
                    ->danger()
                    ->send();
                $this->stage = 'request';
                return;

            case 'too_many_attempts':
                Notification::make()
                    ->title('Too many attempts')
                    ->body('Please request a new code.')
                    ->danger()
                    ->send();
                $this->stage = 'request';
                return;

            case 'no_code':
                Notification::make()
                    ->title('No active code')
                    ->body('Please request a new code.')
                    ->warning()
                    ->send();
                $this->stage = 'request';
                return;

            default:
                Notification::make()
                    ->title('Invalid code')
                    ->body('Check the code from your email and try again.')
                    ->danger()
                    ->send();
        }
    }

    public function backToRequest(): void
    {
        $this->stage = 'request';
        $this->email = null;
        $this->data = [];
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return $this->stage === 'reset' ? 'Reset your password' : 'Forgot password';
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }
}
