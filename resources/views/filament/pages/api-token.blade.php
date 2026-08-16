<x-filament-panels::page>
    <div class="max-w-2xl space-y-4">
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 mb-1">Token de Acesso API</h2>
            <p class="text-sm text-gray-500 mb-4">Gere um token para acesso externo à API do HubAI. O valor completo só é exibido uma vez após gerar.</p>

            <div class="flex gap-3">
                <x-filament::button
                    wire:click="gerarToken"
                    icon="heroicon-o-key"
                    color="primary"
                >
                    Gerar Novo Token
                </x-filament::button>

                @if($this->tokenGerado)
                    <x-filament::button
                        wire:click="revogarTokens"
                        icon="heroicon-o-trash"
                        color="danger"
                        outlined
                    >
                        Revogar Todos os Tokens
                    </x-filament::button>
                @endif
            </div>

            @if($this->token)
                <div class="mt-4 rounded-lg bg-yellow-50 border border-yellow-200 p-4">
                    <p class="text-xs font-semibold text-yellow-800 uppercase mb-2">Copie agora — não será exibido novamente</p>
                    <div class="font-mono text-xs text-yellow-900 break-all bg-yellow-100 rounded p-2">{{ $this->token }}</div>
                </div>
            @endif
        </div>

        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
            <strong>Como usar:</strong> Envie o header <code class="bg-gray-200 px-1 rounded">Authorization: Bearer {token}</code> em todas as requisições à API.
        </div>
    </div>
</x-filament-panels::page>
