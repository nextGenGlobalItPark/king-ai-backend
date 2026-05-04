<x-filament-panels::page.simple>
    @if ($stage === 'request')
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 text-center">
            Enter your email address and we'll send you a 6-digit code to reset your password.
        </p>

        <form wire:submit="requestCode">
            {{ $this->form }}

            <div class="mt-6 flex flex-col gap-3">
                <x-filament::button type="submit" color="primary" class="w-full">
                    Send reset code
                </x-filament::button>
            </div>
        </form>

        <div class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
            <a href="{{ filament()->getLoginUrl() }}" class="underline hover:text-gray-700 dark:hover:text-gray-200">
                Back to sign in
            </a>
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 text-center">
            Enter the 6-digit code we sent to <strong>{{ $email }}</strong>, then choose a new password.
        </p>

        <form wire:submit="resetPassword">
            {{ $this->form }}

            <div class="mt-6 flex flex-col gap-3">
                <x-filament::button type="submit" color="primary" class="w-full">
                    Update password
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="backToRequest"
                    class="w-full"
                >
                    Use a different email
                </x-filament::button>
            </div>
        </form>

        <div class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
            <a href="{{ filament()->getLoginUrl() }}" class="underline hover:text-gray-700 dark:hover:text-gray-200">
                Back to sign in
            </a>
        </div>
    @endif
</x-filament-panels::page.simple>
