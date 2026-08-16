<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Filtros --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <h2 class="text-lg font-semibold mb-3">Filtros de Busca</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Empresa WL</label>
                    <select wire:model="empresaId" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                        <option value="0">-- Selecione --</option>
                        <option value="15">PlugLar (15)</option>
                        <option value="17">JTDrop (17)</option>
                        <option value="20">MEStoreDrop (20)</option>
                        <option value="21">DropKsr (21)</option>
                        <option value="22">Fornecefy (22)</option>
                        <option value="24">MultDrop (24)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Data Fechamento Ciclo</label>
                    <input type="date" wire:model="cycleEnd"
                        class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Janela (dias)</label>
                    <input type="number" wire:model="window" min="1" max="14" value="3"
                        class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                </div>
                <div class="flex items-end">
                    <button wire:click="search"
                        class="w-full bg-primary-600 text-white px-4 py-2 rounded text-sm hover:bg-primary-700 transition">
                        Buscar Suspeitos
                    </button>
                </div>
            </div>
        </div>

        {{-- Mensagem de status --}}
        @if($message)
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-3 text-sm text-blue-800 dark:text-blue-200">
                {{ $message }}
            </div>
        @endif

        {{-- Tabela de suspeitos --}}
        @if($total > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
                <div class="px-4 py-3 border-b dark:border-gray-700 flex justify-between items-center">
                    <h2 class="font-semibold text-red-600 dark:text-red-400">
                        {{ $total }} suspeita(s) de fraude detectada(s)
                    </h2>
                    <span class="text-xs text-gray-500">Ciclo fechou em: {{ $cycleEnd }}</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-2 text-left">Email do Cliente</th>
                            <th class="px-4 py-2 text-left">Acoes Suspeitas</th>
                            <th class="px-4 py-2 text-center">Score</th>
                            <th class="px-4 py-2 text-center">Acao</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($suspects as $suspect)
                            <tr class="border-t dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-4 py-3 font-mono text-xs">{{ $suspect["email"] }}</td>
                                <td class="px-4 py-3">
                                    @foreach($suspect["actions"] as $action)
                                        <div class="text-xs">
                                            <span class="inline-block px-1.5 py-0.5 rounded text-white mr-1
                                                {{ $action["action"] === "delete" ? "bg-red-500" : "bg-orange-500" }}">
                                                {{ $action["action"] === "delete" ? "EXCLUIDO" : "DESATIVADO" }}
                                            </span>
                                            {{ $action["changed_at"] }}
                                            <span class="text-gray-400">(id:{{ $action["client_id"] }})</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold
                                        {{ $suspect["fraud_score"] === "Alto" ? "bg-red-100 text-red-700" : "bg-yellow-100 text-yellow-700" }}">
                                        {{ $suspect["fraud_score"] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button wire:click="chargeAnyway({{ [email] }})"
                                        wire:confirm="Confirma cobrar {{ $suspect["email"] }} mesmo assim?"
                                        class="bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700 transition">
                                        Cobrar Mesmo Assim
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Info de audit log --}}
        <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4 text-xs text-gray-500">
            <p><strong>Como funciona:</strong> O antifraude detecta clientes que foram excluidos ou desativados
            ate {{ $window }} dias antes do fechamento do ciclo e voltaram a ficar ativos ate {{ $window }} dias depois.
            Esse e o padrao classico de manipulacao para escapar da cobranca de R$30/cliente.</p>
            <p class="mt-1"><strong>Observer:</strong> Ativo via WL_ANTIFRAUDE_ENABLED=true no .env do api.hubai.io.
            Snapshot semanal toda segunda 03:00 UTC.</p>
        </div>
    </div>
</x-filament-panels::page>
