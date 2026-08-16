<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 mb-1">Importar / Exportar Produtos em Massa</h2>
            <p class="text-sm text-gray-500 mb-4">Escolha a ação e faça upload da planilha (.xlsx ou .csv).</p>

            <form wire:submit="upload">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-4" icon="heroicon-o-arrow-up-tray">
                    Enviar Planilha
                </x-filament::button>
            </form>
        </div>

        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
            <strong>Ações disponíveis:</strong>
            <ul class="list-disc list-inside mt-2 space-y-1">
                <li><strong>Criar</strong> — Cria novos produtos a partir da planilha</li>
                <li><strong>Atualizar</strong> — Atualiza dados dos produtos existentes (por SKU)</li>
                <li><strong>Preço</strong> — Atualiza apenas o preço dos produtos</li>
                <li><strong>Estoque</strong> — Atualiza apenas o estoque dos produtos</li>
                <li><strong>Remover</strong> — Remove os produtos listados na planilha</li>
            </ul>
        </div>
    </div>
</x-filament-panels::page>
