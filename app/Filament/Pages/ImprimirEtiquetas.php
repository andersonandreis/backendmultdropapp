<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ImprimirEtiquetas extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-printer';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Imprimir Etiquetas';
    protected static ?string $title = 'Imprimir Etiquetas';
    protected static ?string $slug = 'imprimir-etiquetas';
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.imprimir-etiquetas';

    /**
     * MUL-226-07/09: mapping canal (label) → sources aceitos (compat com pedidos legados).
     * Amazon: aceita também variantes que o Bling envia (amazon_dba, amazon_fba, amazon fba, etc).
     * Magalu: aceita 'MagazineLuiza' (source legado) e 'magalu'/'Magalu'.
     * TikTok Shop: substitui o antigo "Outros" — aceita 'tiktok*' e demais sources fora dos canais conhecidos.
     */
    public static function canalSourceMap(): array
    {
        return [
            'MercadoLivre' => ['MercadoLivre', 'mercadolivre', 'mercado_livre'],
            'Shopee'       => ['Shopee', 'shopee'],
            'Amazon'       => ['Amazon', 'amazon', 'amazon_fba', 'amazon_dba', 'amazon fba', 'amazon dba'],
            'Magalu'       => ['Magalu', 'magalu', 'MagazineLuiza', 'magazineluiza', 'magazine_luiza'],
            'TikTok Shop'  => ['TikTok Shop', 'tiktok_shop', 'tiktokshop', 'tiktok', 'TikTok'],
        ];
    }

    public function getCanaisData(): array
    {
        $map = self::canalSourceMap();
        $knownSources = array_merge(...array_values($map));
        $knownSources = array_diff($knownSources, $map['TikTok Shop']);
        $canais = array_keys($map);

        $data = [];
        foreach ($canais as $canal) {
            // MUL-378: a fila de impressao e "pago + etiqueta + nao enviado, ainda nao
            // impressa". Antes filtrava so por order_processing_status IN
            // (awaiting_dispatch, separated) e devolvia 546 linhas: 430 JA ENVIADAS e
            // 466 canceladas/entregues — etiqueta impressa de pedido que ja foi embora.
            // E ao mesmo tempo perdia os 75 pedidos travados em 'awaiting_label' que
            // ja tinham etiqueta baixada. Ambos os casos morrem com o scope.
            $query = Order::query()
                ->readyToShip()
                ->whereNull('label_printed_at');

            if ($canal === 'TikTok Shop') {
                // Cai qualquer fonte fora dos canais conhecidos OU sources reconhecidos como TikTok
                $query->where(function ($q) use ($map, $knownSources) {
                    $q->whereIn('source', $map['TikTok Shop'])
                      ->orWhereNotIn('source', $knownSources);
                });
            } else {
                $query->whereIn('source', $map[$canal]);
            }

            $count = $query->count();

            $data[] = [
                'canal'      => $canal,
                'quantidade' => $count,
                'icon'       => match ($canal) {
                    'MercadoLivre' => 'heroicon-o-shopping-bag',
                    'Shopee'       => 'heroicon-o-fire',
                    'Amazon'       => 'heroicon-o-globe-alt',
                    'Magalu'       => 'heroicon-o-tag',
                    'TikTok Shop'  => 'heroicon-o-musical-note',
                    default        => 'heroicon-o-document-text',
                },
                'cor' => match ($canal) {
                    'MercadoLivre' => 'yellow',
                    'Shopee'       => 'orange',
                    'Amazon'       => 'blue',
                    'Magalu'       => 'purple',
                    'TikTok Shop'  => 'pink',
                    default        => 'gray',
                },
            ];
        }

        return $data;
    }

    public function printAll(string $canal): void
    {
        $map = self::canalSourceMap();
        $knownSources = array_merge(...array_values($map));
        $knownSources = array_diff($knownSources, $map['TikTok Shop']);

        // MUL-378: mesma regra do contador (getCanaisData) — o que imprime tem de ser
        // exatamente o que a tela conta. Ver Order::scopeReadyToShip.
        $query = Order::query()
            ->readyToShip()
            ->whereNull('label_printed_at');

        if ($canal === 'TikTok Shop') {
            $query->where(function ($q) use ($map, $knownSources) {
                $q->whereIn('source', $map['TikTok Shop'])
                  ->orWhereNotIn('source', $knownSources);
            });
        } elseif (isset($map[$canal])) {
            $query->whereIn('source', $map[$canal]);
        } else {
            // canal desconhecido — não faz nada
            $query->whereRaw('1=0');
        }

        $orders = $query->get();
        $count = $orders->count();

        if ($count === 0) {
            \Filament\Notifications\Notification::make()
                ->title('Nenhuma etiqueta pendente')
                ->body("Canal {$canal} sem etiquetas para imprimir.")
                ->warning()
                ->send();
            return;
        }

        // Marca como impressas
        $printedIds = (clone $query)->pluck('id')->all();
        $query->update(['label_printed_at' => now()]);
        \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($printedIds, 'label_printed'); // MUL-276

        \Filament\Notifications\Notification::make()
            ->title("{$count} etiqueta(s) marcada(s) como impressas")
            ->body("Canal: {$canal}")
            ->success()
            ->send();
    }
}
