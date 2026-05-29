{{--
    Rendered via PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE on the Filament
    login page, above the email/password fields.
--}}
<div class="mb-6">
    {{-- Google --}}
    <a
        href="{{ route('filament.oauth.redirect', ['provider' => 'google']) }}"
        class="flex items-center justify-center gap-3 w-full px-4 py-2.5 mb-3
               border border-gray-300 rounded-lg text-sm font-medium
               text-gray-700 bg-white hover:bg-gray-50
               dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700
               transition-colors duration-150"
    >
        {{-- Google 'G' SVG --}}
        <svg class="w-5 h-5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        Continue with Google
    </a>

    {{-- Apple --}}
    <a
        href="{{ route('filament.oauth.redirect', ['provider' => 'apple']) }}"
        class="flex items-center justify-center gap-3 w-full px-4 py-2.5
               border border-gray-900 rounded-lg text-sm font-medium
               text-white bg-gray-900 hover:bg-gray-800
               dark:border-gray-700 dark:bg-gray-950 dark:hover:bg-gray-800
               transition-colors duration-150"
    >
        {{-- Apple logo SVG --}}
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
            <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.7 9.05 7.4c1.39.07 2.35.74 3.15.8 1.19-.24 2.33-.93 3.6-.84 1.53.12 2.68.72 3.44 1.84-3.14 1.88-2.4 5.98.56 7.14-.65 1.67-1.52 3.33-2.75 3.94zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
        </svg>
        Continue with Apple
    </a>

    {{-- Divider --}}
    <div class="relative my-5">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t border-gray-300 dark:border-gray-600"></span>
        </div>
        <div class="relative flex justify-center text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500">
            <span class="bg-white dark:bg-gray-900 px-3">or sign in with email</span>
        </div>
    </div>

    {{-- Show any OAuth error (e.g. "This account does not have admin access") --}}
    @error('oauth')
        <div class="mt-2 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-sm text-red-600 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror
</div>
