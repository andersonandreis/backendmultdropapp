<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncLog;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * MUL-226-13/14: regras GLOBAIS de estoque publicado nos marketplaces (ML/Shopee).
 * - Inflacao (item 13): SOMA fixa sobre o estoque real (ex: 102 + 10.000 = 10.102), 100% do catalogo.
 * - Reserva (item 14): piso de seguranca — real <= piso publica ZERO (preserva unidades pra
 *   pedidos em processamento). PRECEDENCIA: reserva SEMPRE avaliada antes da inflacao.
 * Bling/ERP SEMPRE recebe o estoque real. Default 0/0 = tudo desligado (comportamento anterior).
 */
class RegrasEstoque extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Regras de Estoque';

    protected static ?string $title = 'Regras de Estoque Publicado';

    protected static ?string $navigationGroup = 'Estoque & Remessas';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'regras-estoque';

    protected static string $view = 'filament.pages.regras-estoque';

    public ?array $data = [];

    public function mount(): void
    {
        $saved = DB::table('settings')
            ->whereIn('key', ['stock_inflation_qty', 'stock_reserve_floor'])
            ->pluck('value', 'key');

        $this->form->fill([
            'stock_inflation_qty' => (int) ($saved['stock_inflation_qty'] ?? 0),
            'stock_reserve_floor' => (int) ($saved['stock_reserve_floor'] ?? 0),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('stock_inflation_qty')
                    ->label('Inflação de estoque (unidades SOMADAS ao real)')
                    ->helperText('Soma fixa sobre o estoque real publicado nos marketplaces. Ex: real 102 + inflação 10.000 = publica 10.102. Use 0 pra desligar.')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(99999)
                    ->required(),

                TextInput::make('stock_reserve_floor')
                    ->label('Reserva de estoque (piso de segurança)')
                    ->helperText('Quando o estoque REAL ficar igual ou abaixo deste piso, o marketplace recebe ZERO (para a venda e preserva as unidades pra pedidos em processamento). Avaliada SEMPRE ANTES da inflação. Use 0 pra desligar.')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(99999)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $old = [
            'stock_inflation_qty' => Product::stockRuleSetting('stock_inflation_qty'),
            'stock_reserve_floor' => Product::stockRuleSetting('stock_reserve_floor'),
        ];

        $new = [
            'stock_inflation_qty' => (int) $state['stock_inflation_qty'],
            'stock_reserve_floor' => (int) $state['stock_reserve_floor'],
        ];

        foreach ($new as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['group' => 'stock_rules', 'value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Product::resetStockRulesCache();

        // MUL-226: auditoria de ação crítica — quem mudou, de quê pra quê
        SyncLog::create([
            'syncable_type'   => Setting::class,
            'syncable_id'     => 0,
            'platform'        => 'internal',
            'action'          => 'stock_rules_update',
            'direction'       => 'internal',
            'status'          => 'success',
            'request_payload' => json_encode([
                'old'        => $old,
                'new'        => $new,
                'user_id'    => auth()->id(),
                'user_email' => auth()->user()?->email,
                'origem'     => 'admin/regras-estoque',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        Notification::make()
            ->title('Regras de estoque salvas')
            ->body('Inflação: +' . number_format($new['stock_inflation_qty'], 0, ',', '.') . ' un · Piso de reserva: ' . number_format($new['stock_reserve_floor'], 0, ',', '.') . ' un. Valem pra todo o catálogo nos marketplaces — o Bling continua recebendo o estoque real.')
            ->success()
            ->send();
    }

    public function isMotorLigado(): bool
    {
        return (bool) config('marketplace.sync_inventory_enabled', false);
    }

    /**
     * Prévia: 10 produtos ativos de menor estoque real, com o publicado calculado pelas regras salvas.
     */
    public function getPreviewData(): array
    {
        $inflation = Product::stockRuleSetting('stock_inflation_qty');
        $floor = Product::stockRuleSetting('stock_reserve_floor');

        return Product::query()
            ->where('is_active', 1)
            ->withSum('inventory as real_stock', 'quantity')
            ->orderBy('real_stock')
            ->limit(10)
            ->get()
            ->map(fn (Product $p) => [
                'sku'       => $p->sku,
                'name'      => $p->name,
                'real'      => (int) $p->effective_stock,
                'inflacao'  => $inflation,
                'piso'      => $floor,
                'publicado' => $p->publishedStock(),
            ])
            ->all();
    }
}
