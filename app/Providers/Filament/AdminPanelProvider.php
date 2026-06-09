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
            ->login(\App\Filament\Pages\Auth\Login::class)
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
    fn (): string => view('filament.partials.forgot-password-link')->render()
        . '<div style="text-align:center; margin-top:16px;">
            <a href="/admin/auth/google/redirect"
               style="display:inline-flex; align-items:center; gap:8px;
                      padding:10px 20px; border:1px solid #dadce0;
                      border-radius:6px; text-decoration:none;
                      color:#3c4043; font-size:14px; background:#fff;">
                <svg width="18" height="18" viewBox="0 0 48 48">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.2l6.7-6.7C35.7 2.5 30.2 0 24 0 14.8 0 6.9 5.4 3 13.3l7.8 6C12.7 13 17.9 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17z"/>
                    <path fill="#FBBC05" d="M10.8 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7L2.5 13C.9 16.1 0 19.9 0 24s.9 7.9 2.5 11l8.3-6.3z"/>
                    <path fill="#34A853" d="M24 48c6.2 0 11.4-2 15.2-5.5l-7.5-5.8c-2 1.4-4.6 2.2-7.7 2.2-6.1 0-11.3-3.5-13.2-8.5l-7.8 6C6.9 42.6 14.8 48 24 48z"/>
                </svg>
                Sign in with Google
            </a>
        </div>',
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
