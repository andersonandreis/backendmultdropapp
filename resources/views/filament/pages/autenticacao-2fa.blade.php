<x-filament-panels::page>
    <div class="max-w-lg space-y-4">
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center {{ $this->twoFactorEnabled ? 'bg-green-100' : 'bg-gray-100' }}">
                    <x-heroicon-o-shield-check class="w-5 h-5 {{ $this->twoFactorEnabled ? 'text-green-600' : 'text-gray-400' }}" />
                </div>
                <div>
                    <h2 class="font-semibold text-gray-800">Autenticação de Dois Fatores (2FA)</h2>
                    <p class="text-sm {{ $this->twoFactorEnabled ? 'text-green-600' : 'text-gray-400' }}">
                        {{ $this->twoFactorEnabled ? 'Ativado' : 'Desativado' }}
                    </p>
                </div>
            </div>

            <p class="text-sm text-gray-500 mb-4">
                O 2FA adiciona uma camada extra de segurança à sua conta. Ao fazer login, você precisará de um código do aplicativo autenticador além da sua senha.
            </p>

            @if($this->twoFactorEnabled)
                <x-filament::button
                    wire:click="desativar"
                    color="danger"
                    icon="heroicon-o-x-circle"
                    outlined
                >
                    Desativar 2FA
                </x-filament::button>
            @else
                <x-filament::button
                    wire:click="ativar"
                    color="success"
                    icon="heroicon-o-shield-check"
                >
                    Ativar 2FA
                </x-filament::button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
