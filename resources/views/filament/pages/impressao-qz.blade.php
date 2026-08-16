<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}

        <div class="flex items-center gap-2">
            <x-filament::button type="submit">Salvar preferências</x-filament::button>
            <x-filament::button color="gray" type="button" onclick="detectQzPrinters()">
                Detectar impressoras (QZ Tray)
            </x-filament::button>
        </div>

        <div id="qz-printers-list" class="text-sm text-gray-500"></div>
    </form>

    <div class="mt-6 rounded-lg border p-4 space-y-2 text-sm">
        <div class="font-semibold">Como funciona</div>
        <ol class="list-decimal pl-5 space-y-1 text-gray-600">
            <li>Instale o <a href="https://qz.io/download/" target="_blank" class="text-blue-600 underline">QZ Tray</a> no PC da expedição (Windows/Mac/Linux, ~40 MB).</li>
            <li>Conecte a impressora térmica (Zebra, TSC, POS-58, Elgin i7 etc.) via USB e faça um teste no Windows.</li>
            <li>Volte aqui, clique <strong>Detectar impressoras</strong> e escolha a sua no campo acima.</li>
            <li>Escolha o momento do bip pra imprimir e salve.</li>
            <li>Na primeira operação de impressão, o QZ Tray vai mostrar um pop-up de segurança — marque <strong>"Trust always"</strong> pra não pedir mais.</li>
        </ol>
    </div>

    <script>
        function detectQzPrinters() {
            const box = document.getElementById('qz-printers-list');
            box.textContent = 'Buscando impressoras...';
            if (!window.qz) { box.textContent = 'QZ Tray não carregado (recarregue a página)'; return; }
            (async () => {
                try {
                    if (!qz.websocket.isActive()) { await qz.websocket.connect({ retries: 2, delay: 1 }); }
                    const printers = await qz.printers.find();
                    if (!printers || printers.length === 0) { box.textContent = 'Nenhuma impressora detectada.'; return; }
                    box.innerHTML = '<div class="font-medium mb-1">Impressoras detectadas:</div>' +
                        '<ul class="list-disc pl-5">' +
                        printers.map(p => `<li><code>${p}</code></li>`).join('') + '</ul>' +
                        '<div class="text-xs mt-2">Copie o nome exato pro campo acima.</div>';
                } catch (e) {
                    box.textContent = 'QZ Tray offline no seu PC. Instale/abra ele e tente de novo. (' + e + ')';
                }
            })();
        }
    </script>
</x-filament-panels::page>
