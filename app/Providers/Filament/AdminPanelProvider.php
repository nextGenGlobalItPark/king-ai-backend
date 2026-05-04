<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\ForgotPassword;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\EmailVerify;
use App\Filament\Pages\TwoFactorChallenge;
use App\Filament\Pages\TwoFactorSettings;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureMustChangePassword;
use App\Http\Middleware\EnsureTwoFactorChallenge;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                TwoFactorSettings::class,
                TwoFactorChallenge::class,
                EmailVerify::class,
                ChangePassword::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->userMenuItems([
                'two-factor' => MenuItem::make()
                    ->label('Two-factor authentication')
                    ->url(fn () => TwoFactorSettings::getUrl())
                    ->icon('heroicon-o-shield-check'),
            ])
            // Custom unauthenticated route for the forgot-password page. We
            // intentionally do NOT use Filament's built-in ->passwordReset()
            // method — it ships a token-based reset link flow that's
            // inconsistent with our 6-digit code UX everywhere else.
            ->routes(function () {
                Route::get('/forgot-password', ForgotPassword::class)
                    ->name('auth.password-request');
            })
            // Show a "Forgot password?" link below the login form.
            // AUTH_LOGIN_FORM_AFTER renders inside Filament's built-in login
            // SimplePage layout, after the form actions.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('filament.partials.forgot-password-link')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Order matters here. Each gate is a prerequisite for the
                // next, and each whitelists ONLY its own page + logout to
                // avoid redirect loops.
                //   1. Email verified           — must happen first.
                //   2. Temp password changed    — only allowed once email-verified.
                //   3. 2FA challenge passed     — only after the account is fully set up.
                EnsureEmailVerified::class,
                EnsureMustChangePassword::class,
                EnsureTwoFactorChallenge::class,
            ]);
    }
}
