<?php

namespace App\Filament\Pages;

use App\Services\TwoFactorService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Step-up challenge page shown to admins who have 2FA enabled but haven't
 * passed the challenge for the current session.
 *
 * The user must enter a 6-digit TOTP code OR a recovery code. Successful
 * verification sets `two_factor_passed` in the session and redirects to
 * the dashboard.
 *
 * Access flow:
 *   - Filament Authenticate middleware → user is logged in
 *   - EnsureTwoFactorChallenge middleware → redirected here if not yet passed
 *   - User submits code → session flagged → redirect to dashboard
 *
 * The page is hidden from the navigation (shouldRegisterNavigation = false)
 * and is only reached via the middleware redirect.
 */
class TwoFactorChallenge extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.two-factor-challenge';

    protected static ?string $title = 'Two-Factor Authentication';

    protected static ?string $slug = 'two-factor-challenge';

    public ?array $data = [];

    public bool $useRecoveryCode = false;

    public function mount(): void
    {
        // If 2FA isn't enabled or the challenge already passed, send them
        // home — this page should not be reachable in those states.
        if (! auth()->user()?->hasTwoFactorEnabled()) {
            redirect()->to(filament()->getUrl());
            return;
        }

        if (session('two_factor_passed') === true) {
            redirect()->to(filament()->getUrl());
            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        if ($this->useRecoveryCode) {
            return $form
                ->schema([
                    TextInput::make('recovery_code')
                        ->label('Recovery code')
                        ->placeholder('XXXXXXXXXX')
                        ->required()
                        ->minLength(8)
                        ->maxLength(20)
                        ->autocomplete('one-time-code')
                        ->extraInputAttributes(['autofocus' => true]),
                ])
                ->statePath('data');
        }

        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Authenticator code')
                    ->placeholder('123456')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['autofocus' => true, 'inputmode' => 'numeric']),
            ])
            ->statePath('data');
    }

    /**
     * Toggle button: switch between TOTP and recovery code modes.
     */
    public function toggleRecoveryMode(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->data = [];
        $this->form->fill();
    }

    public function submit(TwoFactorService $twoFactor): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        $passed = false;

        if ($this->useRecoveryCode) {
            $passed = $user->consumeRecoveryCode(
                strtoupper(trim($data['recovery_code'] ?? ''))
            );
        } else {
            $passed = $twoFactor->verifyCode($user, $data['code'] ?? '');
        }

        if (! $passed) {
            Notification::make()
                ->title('Invalid code')
                ->body($this->useRecoveryCode
                    ? 'That recovery code is not valid or has already been used.'
                    : 'That authenticator code is not valid. Check your device clock and try again.')
                ->danger()
                ->send();

            return;
        }

        session(['two_factor_passed' => true]);

        Notification::make()
            ->title('Verified')
            ->success()
            ->send();

        $this->redirect(filament()->getUrl());
    }

    /**
     * Hide from navigation; only the middleware redirects here.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Constrain the form to a narrow centered width — the panel chrome
     * (topbar/sidebar) is still visible, but the user can clearly see this
     * is a focused step-up screen.
     */
    public function getMaxContentWidth(): string
    {
        return 'sm';
    }

    protected function getViewData(): array
    {
        return [
            'useRecoveryCode' => $this->useRecoveryCode,
        ];
    }
}