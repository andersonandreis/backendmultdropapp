<div class="space-y-2">
    @if($movs->isEmpty())
        <p class="text-sm text-gray-500">Sem movimentações registradas.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-1">Data</th>
                    <th>Tipo</th>
                    <th class="text-right">Antes</th>
                    <th class="text-right">Δ</th>
                    <th class="text-right">Depois</th>
                    <th>MP</th>
                    <th>Origem</th>
                    <th>Usuário</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                @foreach($movs as $m)
                <tr class="border-b last:border-0">
                    <td class="py-1 whitespace-nowrap">{{ $m->created_at?->format('d/m/Y H:i') }}</td>
                    <td><span class="px-2 py-0.5 rounded text-xs bg-gray-100">{{ $m->type }}</span></td>
                    <td class="text-right">{{ $m->qty_before }}</td>
                    <td class="text-right {{ $m->qty_change >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $m->qty_change >= 0 ? '+' : '' }}{{ $m->qty_change }}</td>
                    <td class="text-right font-medium">{{ $m->qty_after }}</td>
                    <td>{{ $m->marketplace ?? '—' }}</td>
                    <td class="text-xs">{{ $m->reference_type }}{{ $m->reference_id ? '#'.$m->reference_id : '' }}</td>
                    <td>{{ $m->user?->name ?? '—' }}</td>
                    <td class="text-xs text-gray-600">{{ $m->notes }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
