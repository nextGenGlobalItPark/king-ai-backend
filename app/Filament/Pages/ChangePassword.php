<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Forced password change for admin-created users on first login.
 * Reachable only via the EnsureMustChangePassword middleware redirect.
 *
 * The user does NOT need to confirm their current (temporary) password
 * here — they already proved it to log in. We just take the new password,
 * hash it, and clear the must_change_password flag.
 */
class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static string $view = 'filament.pages.change-password';

    protected static ?string $title = 'Set Your Password';

    protected static ?string $slug = 'change-password';

    public ?array $data = [];

    public function mount(): void
    {
        if (! auth()->user()?->must_change_password) {
            redirect()->to(filament()->getUrl());
            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('new_password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(User::PASSWORD_MIN_LENGTH)
                    ->extraInputAttributes(['autofocus' => true]),
                TextInput::make('new_password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('new_password'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        $user->forceFill([
            'password' => $data['new_password'], // hashed cast on User
            'must_change_password' => false,
        ])->save();

        Notification::make()
            ->title('Password updated')
            ->success()
            ->send();

        $this->redirect(filament()->getUrl());
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
