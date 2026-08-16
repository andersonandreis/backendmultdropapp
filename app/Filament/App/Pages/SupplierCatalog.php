<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use App\Models\Product;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use App\Models\ClientProduct;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use App\Models\Supplier;

class SupplierCatalog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Catálogo do Fornecedor';
    protected static ?string $title = 'Navegar no Catálogo';
    protected static ?string $slug = 'catalogo-de-fornecedores';

    protected static string $view = 'filament.app.pages.supplier-catalog';

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user   = auth()->user();
                $client = $user->client;

                // FOR-029: filtrar por tenant_supplier (plataforma), não por plan_supplier.
                // O plano define limites de SKU/features; a plataforma define quais
                // fornecedores ficam visíveis para os lojistas dela.
                $tenant = \App\Models\Tenant::where('slug', config('bling.app_tenant', 'hubai'))->first();

                $tenantSupplierIds = null; // null = sem filtro (visibility=all)
                if ($tenant && $tenant->default_supplier_visibility === 'scoped') {
                    $tenantSupplierIds = $tenant->suppliers()->pluck('suppliers.id')->toArray();
                }

                $query = \App\Models\Supplier::where('is_active', true);

                if ($tenantSupplierIds !== null) {
                    // Visibilidade restrita: apenas fornecedores do tenant
                    // + estoque privado do próprio lojista
                    $query->where(function ($q) use ($tenantSupplierIds, $client) {
                        $q->whereIn('id', $tenantSupplierIds);
                        if ($client) {
                            $q->orWhere(function ($sub) use ($client) {
                                $sub->where('owner_client_id', $client->id)
                                    ->where('is_private', true);
                            });
                        }
                    });
                } else {
                    // Visibilidade total (ex: tenant hubai / admin) — apenas estoque privado extra
                    if ($client) {
                        $query->where(function ($q) use ($client) {
                            $q->where('is_private', false)
                              ->orWhere(function ($sub) use ($client) {
                                  $sub->where('owner_client_id', $client->id)
                                      ->where('is_private', true);
                              });
                        });
                    }
                }

                return $query;
            })
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Stack::make([
                    ImageColumn::make('logo')->height(150)->extraImgAttributes(['class' => 'object-cover w-full rounded-lg']),
                    Stack::make([
                        TextColumn::make('company_name')->weight('bold')->size(TextColumnSize::Large),
                        TextColumn::make('description')->color('gray')->limit(100),
                    ])->space(2)->extraAttributes(['class' => 'mt-4']),
                ])->space(3),
            ])
            ->actions([
                Action::make('view_catalog')
                    ->label('Acessar Produtos')
                    ->icon('heroicon-m-arrow-right-circle')
                    ->color('primary')
                    ->url(fn(Supplier $record): string => SupplierProductList::getUrl(['supplier' => $record->id])),
            ]);
    }
}
