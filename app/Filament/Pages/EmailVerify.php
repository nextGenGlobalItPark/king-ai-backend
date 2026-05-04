<?php

namespace App\Filament\Pages;

use App\Services\EmailVerificationService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Step-up verification page shown to admins whose email is unverified.
 *
 * Reachable only via the EnsureEmailVerified middleware redirect. A button
 * lets the user resend the code (subject to service-level cooldown).
 */
class EmailVerify extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static string $view = 'filament.pages.email-verify';

    protected static ?string $title = 'Verify Your Email';

    protected static ?string $slug = 'email-verify';

    public ?array $data = [];

    public function mount(): void
    {
        if (auth()->user()?->hasVerifiedEmail()) {
            redirect()->to(filament()->getUrl());
            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('6-digit code from your email')
                    ->placeholder('123456')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['autofocus' => true, 'inputmode' => 'numeric']),
            ])
            ->statePath('data');
    }

    public function submit(EmailVerificationService $verifier): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        $result = $verifier->verify($user, $data['code'] ?? '');

        switch ($result) {
            case 'verified':
                $user->refresh();
                Notification::make()
                    ->title('Email verified')
                    ->success()
                    ->send();
                $this->redirect(filament()->getUrl());
                return;

            case 'expired':
                Notification::make()
                    ->title('Code expired')
                    ->body('Please request a new verification code.')
                    ->danger()
                    ->send();
                return;

            case 'too_many_attempts':
                Notification::make()
                    ->title('Too many attempts')
                    ->body('Please request a new verification code.')
                    ->danger()
                    ->send();
                return;

            case 'no_code':
                Notification::make()
                    ->title('No active code')
                    ->body('Click "Resend code" to receive a new one.')
                    ->warning()
                    ->send();
                return;

            default:
                Notification::make()
                    ->title('Invalid code')
                    ->body('Check the code from your email and try again.')
                    ->danger()
                    ->send();
        }
    }

    public function resend(EmailVerificationService $verifier): void
    {
        $user = auth()->user();

        try {
            $verifier->sendCode($user);
            Notification::make()
                ->title('Code sent')
                ->body('Check your email for a new verification code.')
                ->success()
                ->send();
        } catch (\RuntimeException $e) {
            $remaining = $verifier->cooldownSecondsRemaining($user);
            Notification::make()
                ->title('Please wait')
                ->body("You can request a new code in {$remaining} seconds.")
                ->warning()
                ->send();
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getMaxContentWidth(): string
    {
        return 'sm';
    }
}
