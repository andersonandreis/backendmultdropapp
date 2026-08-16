@php
    /** @var \App\Models\Shipment $record */
    $items = $record->items()->with('product')->orderBy('box_number')->orderBy('id')->get();
    $scanned = $items->whereNotNull('scanned_at')->count();
    $pct = $record->total_items > 0 ? round(($scanned / $record->total_items) * 100, 1) : 0;
@endphp

<div class="space-y-4" x-data="{
    barcode: '',
    box_number: 1,
    status: '',
    items: @js($items->map(fn ($i) => [
        'id' => $i->id,
        'sku' => $i->product?->sku,
        'name' => $i->product?->name,
        'qty' => $i->quantity,
        'received' => $i->quantity_received,
        'scanned' => (bool) $i->scanned_at,
        'box_number' => $i->box_number,
        'label_code' => $i->label_code,
    ])),
    csrf: '{{ csrf_token() }}',
    async scan() {
        if (!this.barcode.trim()) return;
        const resp = await fetch('/api/v1/supplier/shipments/{{ $record->id }}/scan-item', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrf,
            },
            body: JSON.stringify({
                barcode: this.barcode.trim(),
                box_number: this.box_number,
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (data.ok && !data.duplicate) {
            this.status = 'OK: ' + (data.sku || data.barcode) + ' bipado';
            const it = this.items.find(x => x.id === data.item_id);
            if (it) { it.scanned = true; it.received = it.qty; it.box_number = this.box_number; }
            // beep verde via audio context
            try { const ac = new (window.AudioContext || window.webkitAudioContext)(); const o = ac.createOscillator(); o.frequency.value = 880; o.connect(ac.destination); o.start(); o.stop(ac.currentTime + 0.08); } catch(e) {}
        } else if (data.duplicate) {
            this.status = 'JÁ BIPADO: ' + (data.sku || this.barcode);
        } else {
            this.status = 'ERRO: ' + (data.reason || 'desconhecido');
            try { const ac = new (window.AudioContext || window.webkitAudioContext)(); const o = ac.createOscillator(); o.frequency.value = 220; o.connect(ac.destination); o.start(); o.stop(ac.currentTime + 0.3); } catch(e) {}
        }
        this.barcode = '';
        this.$nextTick(() => this.$refs.scanInput?.focus());
    }
}">

    <div class="grid grid-cols-3 gap-3 text-sm">
        <div class="border rounded p-2 text-center">
            <div class="text-gray-500">Total</div>
            <div class="text-2xl font-bold">{{ $record->total_items }}</div>
        </div>
        <div class="border rounded p-2 text-center">
            <div class="text-gray-500">Bipados</div>
            <div class="text-2xl font-bold text-green-600">{{ $scanned }}</div>
        </div>
        <div class="border rounded p-2 text-center">
            <div class="text-gray-500">Progresso</div>
            <div class="text-2xl font-bold">{{ $pct }}%</div>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-2 items-end">
        <div class="col-span-2">
            <label class="block text-sm text-gray-600">Código de barras / etiqueta</label>
            <input
                x-ref="scanInput"
                x-model="barcode"
                @keydown.enter.prevent="scan()"
                x-init="$nextTick(() => $refs.scanInput.focus())"
                class="w-full px-3 py-2 border rounded text-lg font-mono"
                placeholder="bipe ou digite..."
                autocomplete="off">
        </div>
        <div>
            <label class="block text-sm text-gray-600">Caixa</label>
            <input type="number" x-model.number="box_number" min="1" max="99" class="w-full px-3 py-2 border rounded">
        </div>
    </div>

    <div x-text="status" class="text-center text-lg font-semibold p-2" :class="status.startsWith('OK') ? 'text-green-600' : (status.startsWith('JÁ') ? 'text-yellow-600' : (status.startsWith('ERRO') ? 'text-red-600' : ''))"></div>

    <div class="max-h-64 overflow-y-auto border rounded">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 sticky top-0">
                <tr>
                    <th class="px-2 py-1 text-left">SKU</th>
                    <th class="px-2 py-1 text-left">Produto</th>
                    <th class="px-2 py-1 text-center">Cx</th>
                    <th class="px-2 py-1 text-center">Qtd</th>
                    <th class="px-2 py-1 text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="it in items" :key="it.id">
                    <tr :class="it.scanned ? 'bg-green-50' : ''">
                        <td class="px-2 py-1 font-mono text-xs" x-text="it.sku || '-'"></td>
                        <td class="px-2 py-1 text-xs" x-text="it.name || '-'"></td>
                        <td class="px-2 py-1 text-center" x-text="it.box_number || '-'"></td>
                        <td class="px-2 py-1 text-center" x-text="it.received + '/' + it.qty"></td>
                        <td class="px-2 py-1 text-center">
                            <span x-show="it.scanned" class="text-green-600">OK</span>
                            <span x-show="!it.scanned" class="text-gray-400">--</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
