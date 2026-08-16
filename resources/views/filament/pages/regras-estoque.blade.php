<x-filament-panels::page>
    {{-- MUL-226-13/14: aviso do gate do motor de sync automático --}}
    @if (!$this->isMotorLigado())
        <div style="border-radius:12px;border:1px solid rgba(245,158,11,0.45);background:rgba(245,158,11,0.10);padding:12px 16px;">
            <div style="font-weight:700;color:#b45309;">Motor de sync automático de estoque está DESLIGADO</div>
            <div class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:2px;">
                Gate de segurança ativo (MARKETPLACE_SYNC_INVENTORY_ENABLED=false, incidente 28/05).
                As regras abaixo <strong>já valem</strong> na publicação e republicação de anúncios (criação no Mercado Livre)
                e passam a valer também no sync automático de estoque quando o motor for religado — decisão à parte.
            </div>
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Regras globais (100% do catálogo)</x-slot>
        <x-slot name="description">
            Ordem de aplicação: 1º RESERVA — se o estoque real estiver igual ou abaixo do piso, publica ZERO;
            2º INFLAÇÃO — soma fixa sobre o real. Valem só pros marketplaces de venda (ML/Shopee) —
            o Bling/ERP sempre recebe o estoque REAL. Deixar os dois em 0 desliga tudo.
        </x-slot>

        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}

            <x-filament::button type="submit" icon="heroicon-o-check">
                Salvar regras
            </x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Prévia — 10 produtos ativos de menor estoque real</x-slot>
        <x-slot name="description">Calculada com as regras salvas acima (salve pra atualizar).</x-slot>

        <div style="overflow-x:auto;">
            <table class="w-full text-sm" style="border-collapse:collapse;">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400" style="text-align:left;border-bottom:1px solid rgba(148,163,184,0.35);">
                        <th style="padding:8px 10px;">SKU</th>
                        <th style="padding:8px 10px;">Produto</th>
                        <th style="padding:8px 10px;text-align:center;">Real</th>
                        <th style="padding:8px 10px;text-align:center;">Piso reserva</th>
                        <th style="padding:8px 10px;text-align:center;">Inflação</th>
                        <th style="padding:8px 10px;text-align:center;">Publicado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getPreviewData() as $row)
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.15);">
                            <td style="padding:8px 10px;font-weight:600;">#{{ $row['sku'] }}</td>
                            <td style="padding:8px 10px;">{{ \Illuminate\Support\Str::limit($row['name'], 55) }}</td>
                            <td style="padding:8px 10px;text-align:center;">{{ number_format($row['real'], 0, ',', '.') }}</td>
                            <td style="padding:8px 10px;text-align:center;color:#94a3b8;">{{ $row['piso'] > 0 ? number_format($row['piso'], 0, ',', '.') : '—' }}</td>
                            <td style="padding:8px 10px;text-align:center;color:#94a3b8;">{{ $row['inflacao'] > 0 ? '+' . number_format($row['inflacao'], 0, ',', '.') : '—' }}</td>
                            <td style="padding:8px 10px;text-align:center;font-weight:800;color:{{ $row['publicado'] > 0 ? '#059669' : '#dc2626' }};">
                                {{ number_format($row['publicado'], 0, ',', '.') }}
                                @if ($row['piso'] > 0 && $row['real'] <= $row['piso'])
                                    <span class="text-xs" style="color:#dc2626;">(piso)</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:12px;text-align:center;color:#94a3b8;">Nenhum produto ativo encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
