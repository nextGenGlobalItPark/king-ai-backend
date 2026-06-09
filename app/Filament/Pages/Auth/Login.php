<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();
    }

    protected function getFormActions(): array
    {
        return array_merge(parent::getFormActions(), []);
    }

    public function getView(): string
    {
        return 'filament.pages.auth.login';
    }
}