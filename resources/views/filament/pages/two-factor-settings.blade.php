<x-filament-panels::page>
    @php $state = $this->state; @endphp

    {{-- Recovery codes box: shown ONCE after confirm or regenerate --}}
    @if ($newRecoveryCodes)
        <x-filament::section icon="heroicon-o-key" icon-color="warning">
            <x-slot name="heading">Save your recovery codes</x-slot>
            <x-slot name="description">
                Each code can be used once if you lose access to your authenticator. They will not be shown again.
            </x-slot>

            <div class="grid grid-cols-2 gap-2 font-mono text-sm bg-gray-50 dark:bg-gray-900 p-4 rounded">
                @foreach ($newRecoveryCodes as $code)
                    <div class="select-all">{{ $code }}</div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-2">
                <x-filament::button color="primary" wire:click="dismissRecoveryCodes">
                    I've saved my recovery codes
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- DISABLED state --}}
    @if ($state === 'disabled')
        <x-filament::section icon="heroicon-o-shield-check">
            <x-slot name="heading">Two-factor authentication is off</x-slot>
            <x-slot name="description">
                Add an extra layer of security to your account by requiring a code from your authenticator app at sign-in.
            </x-slot>

            <x-filament::button color="primary" wire:click="startSetup">
                Set up 2FA
            </x-filament::button>
        </x-filament::section>
    @endif

    {{-- PENDING state: QR + verify form --}}
    @if ($state === 'pending')
        <x-filament::section icon="heroicon-o-qr-code">
            <x-slot name="heading">Scan this QR code</x-slot>
            <x-slot name="description">
                Open your authenticator app (Google Authenticator, 1Password, Authy, etc.) and scan the code below.
                Then enter the 6-digit code it shows you to finish setup.
            </x-slot>

            <div class="flex flex-col md:flex-row gap-6 items-start">
                <div class="bg-white p-3 rounded shadow-sm">
                    {!! $qrCodeSvg !!}
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Or enter this secret manually:</p>
                        <code class="block mt-1 text-sm font-mono bg-gray-100 dark:bg-gray-800 p-2 rounded select-all break-all">{{ $pendingSecret }}</code>
                    </div>

                    <form wire:submit="confirmSetup" class="space-y-4">
                        {{ $this->confirmForm }}

                        <div class="flex gap-2">
                            <x-filament::button type="submit" color="primary">
                                Verify and enable
                            </x-filament::button>

                            {{ $this->cancelSetupAction }}
                        </div>
                    </form>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- ENABLED state --}}
    @if ($state === 'enabled')
        <x-filament::section icon="heroicon-o-shield-check" icon-color="success">
            <x-slot name="heading">Two-factor authentication is enabled</x-slot>
            <x-slot name="description">
                Confirmed on {{ auth()->user()->two_factor_confirmed_at?->format('M j, Y \a\t g:i a') }}.
            </x-slot>

            <div class="flex flex-wrap gap-2">
                {{ $this->regenerateCodesAction }}
                {{ $this->disableAction }}
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
