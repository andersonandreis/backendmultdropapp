<x-filament-panels::page>
    <div class="max-w-3xl space-y-4">
        <form wire:submit="salvar">
            {{ $this->form }}

            <div class="flex gap-3 mt-6">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Salvar Configuracoes
                </x-filament::button>

                <x-filament::button
                    type="button"
                    wire:click="testarConexao"
                    color="info"
                    icon="heroicon-o-signal"
                    outlined
                >
                    Testar Conexao
                </x-filament::button>
            </div>
        </form>
    </div>

    <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800 max-w-3xl">
        <strong>Como usar:</strong>
        <ol class="mt-2 list-decimal list-inside space-y-1">
            <li>Insira sua chave OpenAI e salve.</li>
            <li>Use o botao "Testar Conexao" para validar a chave.</li>
            <li>Configure prompts por marketplace (opcional) para personalizar o tom da IA.</li>
            <li>Ative a IA com o toggle acima.</li>
            <li>Seus sellers verao os botoes "Gerar com IA" no cadastro de produto.</li>
        </ol>
    </div>
</x-filament-panels::page>
