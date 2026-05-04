<x-filament-panels::page>
    <div class="max-w-md mx-auto w-full">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/30 mb-3">
                <x-filament::icon
                    icon="heroicon-o-key"
                    class="w-6 h-6 text-amber-600 dark:text-amber-400"
                />
            </div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                Set your password
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Choose a new password to replace the temporary one you were given.
            </p>
        </div>

        <form wire:submit="submit">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" color="primary" class="w-full">
                    Save password
                </x-filament::button>
            </div>
        </form>

        <div class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
            <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="inline">
                @csrf
                <button type="submit" class="underline hover:text-gray-700 dark:hover:text-gray-200">
                    Sign out
                </button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
