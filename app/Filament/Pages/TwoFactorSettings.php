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
use Illuminate\Support\Facades\Hash;

/**
 * Self-service 2FA management for the logged-in admin.
 *
 * State machine:
 *   1. Disabled              → "Set up 2FA" button → generates secret, shows QR
 *   2. Pending (secret set,  → Shows QR + verify form. Verify → Enabled.
 *      not confirmed)
 *   3. Enabled               → Shows status + "Regenerate codes" + "Disable" actions
 *                              (both require password reconfirm).
 *
 * Recovery codes are shown ONCE after initial setup or after regeneration —
 * they're stored briefly in component state and cleared on the next refresh.
 * The user is told to copy them now.
 */
class TwoFactorSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.two-factor-settings';

    protected static ?string $navigationGroup = 'Account';

    protected static ?string $title = 'Two-Factor Authentication';

    protected static ?string $slug = 'two-factor-settings';

    /** Form data for the in-flight confirmation step (during pending setup). */
    public ?array $confirmData = [];

    /** QR/secret for the current pending setup, populated after "Set up 2FA". */
    public ?string $qrCodeSvg = null;

    public ?string $pendingSecret = null;

    /** Recovery codes shown ONCE after confirm or regenerate. */
    public ?array $newRecoveryCodes = null;

    public function mount(): void
    {
        $this->confirmForm->fill();

        // If 2FA is in pending state from a previous visit, surface the QR
        // again so the user can finish what they started.
        $user = auth()->user();
        if ($user->two_factor_secret && ! $user->hasTwoFactorEnabled()) {
            $this->showPendingQr();
        }
    }

    /**
     * The setup state is one of: disabled, pending, enabled. The blade uses
     * this to pick which UI section to render.
     */
    public function getStateProperty(): string
    {
        $user = auth()->user();
        if ($user->hasTwoFactorEnabled()) {
            return 'enabled';
        }
        if ($user->two_factor_secret) {
            return 'pending';
        }
        return 'disabled';
    }

    /**
     * Form used during the "pending → enabled" confirmation step.
     */
    public function confirmForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Enter the 6-digit code from your authenticator')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['inputmode' => 'numeric']),
            ])
            ->statePath('confirmData');
    }

    protected function getForms(): array
    {
        return ['confirmForm'];
    }

    /**
     * "Set up 2FA" button — generates a fresh secret + QR.
     */
    public function startSetup(TwoFactorService $twoFactor): void
    {
        try {
            $data = $twoFactor->generateSecret(auth()->user());
            $this->qrCodeSvg = $data['qr_code_svg'];
            $this->pendingSecret = $data['secret'];

            // Refresh user instance so getStateProperty reflects pending state
            auth()->user()->refresh();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Could not start 2FA setup')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Re-render the QR if the user navigated away mid-setup.
     */
    private function showPendingQr(): void
    {
        $user = auth()->user();
        if (! $user->two_factor_secret) {
            return;
        }

        $secret = decrypt($user->two_factor_secret);
        $google2fa = app('pragmarx.google2fa');
        $otpauthUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'Vidio King'),
            $user->email,
            $secret
        );

        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(192),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );

        $this->qrCodeSvg = (new \BaconQrCode\Writer($renderer))->writeString($otpauthUrl);
        $this->pendingSecret = $secret;
    }

    /**
     * "Verify" button on the pending-setup form.
     */
    public function confirmSetup(TwoFactorService $twoFactor): void
    {
        $data = $this->confirmForm->getState();

        try {
            $codes = $twoFactor->confirmSetup(auth()->user(), $data['code']);

            // Once confirmed, the admin must pass 2FA next time — but we mark
            // this session as already-passed so they don't have to re-challenge
            // immediately after enabling.
            session(['two_factor_passed' => true]);

            $this->newRecoveryCodes = $codes;
            $this->qrCodeSvg = null;
            $this->pendingSecret = null;
            $this->confirmData = [];
            auth()->user()->refresh();

            Notification::make()
                ->title('Two-factor authentication enabled')
                ->body('Save your recovery codes — they will not be shown again.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Verification failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Cancel a pending setup (clears the secret).
     */
    public function cancelSetupAction(): Action
    {
        return Action::make('cancelSetup')
            ->label('Cancel setup')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Cancel 2FA setup?')
            ->modalDescription('This will discard the QR code. You can start over anytime.')
            ->action(function (TwoFactorService $twoFactor) {
                $twoFactor->disable(auth()->user());
                $this->qrCodeSvg = null;
                $this->pendingSecret = null;
                auth()->user()->refresh();
            });
    }

    /**
     * "Regenerate recovery codes" — requires password reconfirmation.
     */
    public function regenerateCodesAction(): Action
    {
        return Action::make('regenerateCodes')
            ->label('Regenerate recovery codes')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Regenerate recovery codes?')
            ->modalDescription('Old codes will stop working immediately.')
            ->form([
                TextInput::make('password')
                    ->label('Confirm your password')
                    ->password()
                    ->required(),
            ])
            ->action(function (array $data, TwoFactorService $twoFactor) {
                if (! Hash::check($data['password'], auth()->user()->password)) {
                    Notification::make()
                        ->title('Incorrect password')
                        ->danger()
                        ->send();
                    return;
                }

                $this->newRecoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user());

                Notification::make()
                    ->title('Recovery codes regenerated')
                    ->body('Save the new codes — old ones no longer work.')
                    ->success()
                    ->send();
            });
    }

    /**
     * "Disable 2FA" — requires password reconfirmation.
     */
    public function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Disable 2FA')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Disable two-factor authentication?')
            ->modalDescription('Your account will only be protected by your password.')
            ->form([
                TextInput::make('password')
                    ->label('Confirm your password')
                    ->password()
                    ->required(),
            ])
            ->action(function (array $data, TwoFactorService $twoFactor) {
                if (! Hash::check($data['password'], auth()->user()->password)) {
                    Notification::make()
                        ->title('Incorrect password')
                        ->danger()
                        ->send();
                    return;
                }

                $twoFactor->disable(auth()->user());
                session()->forget('two_factor_passed');
                auth()->user()->refresh();

                Notification::make()
                    ->title('Two-factor authentication disabled')
                    ->success()
                    ->send();
            });
    }

    /**
     * Dismiss the recovery codes box once the user confirms they've saved them.
     */
    public function dismissRecoveryCodes(): void
    {
        $this->newRecoveryCodes = null;
    }
}
