<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Jobs\GenerateProductContentJob;
use App\Exports\ProductsExport;
use App\Exports\ProductsTemplateExport;
use App\Imports\ProductsImport;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\Action as FilamentAction;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $slug = 'produtos';
    protected static ?string $modelLabel = 'Produto';
    protected static ?string $pluralModelLabel = 'Produtos';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?int $navigationSort = 1;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informações Gerais')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->relationship('supplier', 'company_name')
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sku')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('service_sku')
                            ->label('Código do serviço')
                            ->helperText('SKU do serviço de embalagem enviado ao Bling na NF (opcional)')
                            ->maxLength(100),
                        Forms\Components\Textarea::make('description')
                            ->columnSpanFull(),
                        Forms\Components\MarkdownEditor::make('rich_description')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Precificação')
                    ->schema([
                        Forms\Components\TextInput::make('cost')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                        Forms\Components\TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->minValue(0.01) // MUL-198: price nunca zero
                            ->prefix('R$')
                            ->default(0.00),
                        Forms\Components\TextInput::make('promotional_price')
                            ->numeric()
                            ->prefix('R$'),
                    ])->columns(3),

                Forms\Components\Section::make('Logística e Dimensões')
                    ->schema([
                        Forms\Components\TextInput::make('weight_kg')
                            ->label('Peso (kg)')
                            ->numeric()
                            ->suffix('kg')
                            ->default(0.00),
                        Forms\Components\TextInput::make('length_cm')
                            ->label('Comprimento (cm)')
                            ->numeric()
                            ->suffix('cm'),
                        Forms\Components\TextInput::make('width_cm')
                            ->label('Largura (cm)')
                            ->numeric()
                            ->suffix('cm'),
                        Forms\Components\TextInput::make('height_cm')
                            ->label('Altura (cm)')
                            ->numeric()
                            ->suffix('cm'),
                    ])->columns(4),

                Forms\Components\Section::make('Identificadores e Marketplace')
                    ->schema([
                        Forms\Components\TextInput::make('brand')
                            ->label('Marca')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->label('Modelo')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('gtin')
                            ->label('GTIN')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('ean')
                            ->label('EAN')
                            ->maxLength(20),
                        Forms\Components\Select::make('condition')
                            ->label('Condição')
                            ->options([
                                'new' => 'Novo',
                                'used' => 'Usado',
                                'refurbished' => 'Recondicionado',
                            ])
                            ->default('new'),
                        Forms\Components\TextInput::make('warranty_months')
                            ->label('Garantia (meses)')
                            ->numeric()
                            ->suffix('meses'),
                        Forms\Components\TextInput::make('video_url')
                            ->label('URL do Vídeo (YouTube/ML)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])->columns(3),

                // MUL-161 Item 20: campos de conformidade e fabricante (aditivo)
                Forms\Components\Section::make('Conformidade e Fabricante')
                    ->schema([
                        Forms\Components\TextInput::make('inmetro')
                            ->label('Certificado INMETRO')
                            ->maxLength(100)
                            ->placeholder('Ex: 123/2024'),
                        Forms\Components\TextInput::make('homologation_number')
                            ->label('Homologacao (ANATEL/ANVISA)')
                            ->maxLength(100)
                            ->placeholder('Ex: 01234-24-00000'),
                        Forms\Components\TextInput::make('manufacturer')
                            ->label('Fabricante')
                            ->maxLength(255)
                            ->placeholder('Nome do fabricante'),
                        Forms\Components\TextInput::make('ncm')
                            ->label('NCM')
                            ->maxLength(20)
                            ->placeholder('Ex: 8517.12.31'),
                        Forms\Components\Select::make('origin')
                            ->label('Origem')
                            ->options([
                                0 => 'Nacional',
                                1 => 'Importado',
                                2 => 'Nacional - 40% ou mais de conteudo importado',
                                3 => 'Nacional - 70% ou mais de conteudo importado',
                                4 => 'Nacional - producao conforme processo produtivo basico',
                                5 => 'Nacional - mercadoria ou bem com Conteudo de Importacao inferior ou igual a 40%',
                                6 => 'Estrangeiro - Adquirido no mercado interno',
                                7 => 'Estrangeiro - Adquirido ou recebido do exterior',
                            ])
                            ->default(0),
                    ])->columns(3)->collapsible(),

                Forms\Components\Section::make('Estoque Virtual')
                    ->description('Controle o estoque virtual exibido aos lojistas.')
                    ->schema([
                        Forms\Components\TextInput::make('virtual_stock_qty')
                            ->label('Estoque Virtual')
                            ->numeric(),
                        Forms\Components\TextInput::make('safety_margin_stock')
                            ->label('Margem de Segurança')
                            ->numeric()
                            ->suffix('unid.')
                            ->default(20),
                        Forms\Components\TextInput::make('zero_out_margin_stock')
                            ->label('Margem para Zerar')
                            ->numeric()
                            ->suffix('unid.')
                            ->default(10),
                    ])->columns(3),

                Forms\Components\Section::make('Variações (Grades)')
                    ->schema([
                        Forms\Components\Repeater::make('variations')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('sku')
                                    ->required()->maxLength(255),
                                Forms\Components\TextInput::make('name')
                                    ->required()->maxLength(255)->label('Nome da Variação (ex: Cor / Tamanho)'),
                                Forms\Components\TextInput::make('price_modifier')
                                    ->numeric()->default(0)->prefix('R$'),
                                Forms\Components\TextInput::make('gtin')
                                    ->maxLength(255),
                                Forms\Components\Toggle::make('is_active')
                                    ->default(true),
                            ])->columns(3)
                    ]),

                Forms\Components\Section::make('Alimentador de Mídias (Fotos e Vídeos Extras)')
                    ->description('Vitrine rica vende mais! Anexe fotos de estilo de vida, ângulos e vídeos (MP4) curtos do produto. Os Lojistas poderão baixar estes arquivos para enriquecer e diversificar os anúncios deles na Shopee/ML.')
                    ->schema([
                        Forms\Components\Repeater::make('media')
                            ->relationship()
                            ->label('Mídias Extras do Produto')
                            ->addActionLabel('Adicionar nova Foto ou Vídeo')
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Tipo de Mídia')
                                    ->options([
                                        'image' => 'Foto (JPG/PNG/WEBP)',
                                        'video' => 'Vídeo Curto (MP4)'
                                    ])
                                    ->default('image')
                                    ->required()
                                    ->live(),
                                Forms\Components\FileUpload::make('url')
                                    ->label('Arquivo (Upload)')
                                    ->directory('products/media')
                                    ->acceptedFileTypes(['image/*', 'video/mp4', 'video/quicktime'])
                                    ->maxSize(51200), // 50MB
                                Forms\Components\TextInput::make('position')
                                    ->label('Ordem (Prioridade)')
                                    ->numeric()
                                    ->default(0),
                            ])
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => ($state['type'] ?? '') === 'video' ? '🎬 Vídeo MP4' : '📸 Imagem')
                            ->columns(3)
                    ]),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->required()
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        // MUL-226-12: toggle Lista/Grade da ListProducts (grade é o default)
        $livewire = $table->getLivewire();
        $isGrid = $livewire instanceof Pages\ListProducts && $livewire->isGridLayout;

        return $table
            ->defaultPaginationPageOption(25)
            ->contentGrid($isGrid ? ['md' => 2, 'lg' => 3, '2xl' => 4] : null)
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->headerActions([
                Tables\Actions\Action::make('export_products')
                    ->label('Exportar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () {
                        return Excel::download(new ProductsExport(), 'produtos-' . now()->format('Y-m-d') . '.xlsx');
                    }),
                Tables\Actions\Action::make('import_products')
                    ->label('Importar')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->form([
                        \Filament\Forms\Components\FileUpload::make('file')
                            ->label('Arquivo Excel/CSV')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'application/vnd.ms-excel'])
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        Excel::import(new ProductsImport(), storage_path('app/public/' . $data['file']));
                        \Filament\Notifications\Notification::make()
                            ->title('Importação concluída')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('baixar_template')
                    ->label('Baixar Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->url(fn() => route('admin.products.template'))
                    ->openUrlInNewTab(),
            ])
            ->columns($isGrid ? self::getGridColumns() : self::getListColumns())
            ->defaultPaginationPageOption(25)
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->hidden(fn() => auth()->user()?->role !== 'super_admin'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Cadastro ativo')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos')
                    ->placeholder('Todos'),
                Tables\Filters\Filter::make('com_estoque')
                    ->label('Apenas com estoque > 0')
                    ->toggle()
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) =>
                        $query->whereExists(function ($q) {
                            $q->select(\Illuminate\Support\Facades\DB::raw(1))
                              ->from('inventory')
                              ->whereColumn('inventory.product_id', 'products.id')
                              ->where('inventory.quantity', '>', 0);
                        })
                    ),
                Tables\Filters\Filter::make('com_foto')
                    ->label('Com foto')
                    ->toggle()
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) =>
                        $query->whereExists(function ($q) {
                            $q->select(\Illuminate\Support\Facades\DB::raw(1))
                              ->from('product_media')
                              ->whereColumn('product_media.product_id', 'products.id')
                              ->where('product_media.type', 'image');
                        })
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_ai_content')
                    ->label('Gerar com IA')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar conteúdo com IA')
                    ->modalDescription('Isso irá gerar título, descrição e bullet points otimizados para marketplaces. O processo pode levar alguns segundos.')
                    ->action(fn (Product $record) => GenerateProductContentJob::dispatch($record->id))
                    ->successNotificationTitle('Geração iniciada! O conteúdo será atualizado em breve.'),
                Tables\Actions\ViewAction::make()->label('Detalhes')
                    ->modalHeading('Ficha do Produto Administrativa')
                    ->modalWidth('7xl')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->infolist([
                        // --- LINHA 1: Galeria + Dados Principais ---
                        \Filament\Infolists\Components\Grid::make(12)
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Imagens')
                                    ->schema([
                                        \Filament\Infolists\Components\ViewEntry::make('gallery')
                                            ->label('')
                                            ->view('filament.components.product-carousel')
                                            ->getStateUsing(fn(Product $record) => $record->media()->pluck('url')->toArray())
                                    ])
                                    ->columnSpan(['default' => 12, 'md' => 5]),

                                \Filament\Infolists\Components\Section::make('Dados Principais')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('name')->label('Produto')->weight('bold')->size('lg')->columnSpanFull(),
                                        \Filament\Infolists\Components\TextEntry::make('sku')->label('SKU Base')->badge(),
                                        \Filament\Infolists\Components\TextEntry::make('status')->label('Status')->badge(),
                                        \Filament\Infolists\Components\TextEntry::make('supplier.company_name')->label('Fornecedor')->badge()->color('info'),
                                        \Filament\Infolists\Components\TextEntry::make('category.name')->label('Categoria')->badge()->color('warning'),
                                        \Filament\Infolists\Components\IconEntry::make('is_active')->label('Ativo')->boolean(),
                                        \Filament\Infolists\Components\TextEntry::make('condition')
                                            ->label('Condição')
                                            ->badge()
                                            ->formatStateUsing(fn($state) => match($state) {
                                                'new' => 'Novo', 'used' => 'Usado', 'refurbished' => 'Recondicionado', default => $state ?? '—'
                                            }),
                                        \Filament\Infolists\Components\TextEntry::make('description')->label('Descrição')->html()->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpan(['default' => 12, 'md' => 7]),
                            ]),

                        // --- LINHA 2: Precificação + Dimensões ---
                        \Filament\Infolists\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Precificação')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('cost')->label('Custo')->money('BRL')->color('danger'),
                                        \Filament\Infolists\Components\TextEntry::make('price')->label('Preço de Venda')->money('BRL')->color('success')->weight('bold'),
                                    ])
                                    ->columns(2),

                                \Filament\Infolists\Components\Section::make('Dimensões e Peso')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('weight_kg')->label('Peso')->suffix(' kg')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('length_cm')->label('Comprimento')->suffix(' cm')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('width_cm')->label('Largura')->suffix(' cm')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('height_cm')->label('Altura')->suffix(' cm')->placeholder('—'),
                                    ])
                                    ->columns(4),
                            ]),

                        // --- LINHA 3: Identificadores + Garantia ---
                        \Filament\Infolists\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Identificadores')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('gtin')->label('GTIN')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('ean')->label('EAN')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('brand')->label('Marca')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('model')->label('Modelo')->placeholder('—'),
                                    ])
                                    ->columns(2),

                                \Filament\Infolists\Components\Section::make('Garantia e Marketplace')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('warranty_months')->label('Garantia')->suffix(' meses')->placeholder('—'),
                                        \Filament\Infolists\Components\TextEntry::make('video_url')
                                            ->label('Vídeo (URL)')
                                            ->url(fn($state) => $state)
                                            ->openUrlInNewTab()
                                            ->placeholder('—')
                                            ->limit(50),
                                    ])
                                    ->columns(2),
                            ]),

                        // --- LINHA 4: Estoque Virtual ---
                        \Filament\Infolists\Components\Section::make('Estoque Virtual')
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('virtual_stock_qty')->label('Qtd. Estoque Virtual')->placeholder('—'),
                                \Filament\Infolists\Components\TextEntry::make('safety_margin_stock')->label('Margem de Segurança')->suffix(' unid.'),
                                \Filament\Infolists\Components\TextEntry::make('zero_out_margin_stock')->label('Margem para Zerar')->suffix(' unid.'),
                            ])
                            ->columns(3),

                        // --- LINHA 5: Conteúdo IA ---
                        \Filament\Infolists\Components\Section::make('Conteúdo Gerado por IA')
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('ai_title')->label('Título IA')->placeholder('Não gerado ainda')->columnSpanFull(),
                                \Filament\Infolists\Components\TextEntry::make('ai_description')->label('Descrição IA')->html()->placeholder('Não gerado ainda')->columnSpanFull(),
                                \Filament\Infolists\Components\TextEntry::make('ai_bullet_points')
                                    ->label('Bullet Points IA')
                                    ->placeholder('Não gerado ainda')
                                    ->formatStateUsing(function ($state) {
                                        if (!$state) return '—';
                                        $items = is_string($state) ? json_decode($state, true) : $state;
                                        if (!is_array($items)) return $state;
                                        return implode('<br>• ', array_merge(['• '], $items));
                                    })
                                    ->html()
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->collapsible(),
                    ]),
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // NOV-128: ativar/desativar em lote
                    Tables\Actions\BulkAction::make('ativarLote')
                        ->label('Ativar selecionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $n = 0;
                            foreach ($records as $r) { $r->is_active = true; $r->save(); $n++; }
                            \Filament\Notifications\Notification::make()->title("{$n} ativados")->success()->send();
                        }),
                    Tables\Actions\BulkAction::make('desativarLote')
                        ->label('Desativar selecionados')
                        ->icon('heroicon-o-no-symbol')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $n = 0;
                            foreach ($records as $r) { $r->is_active = false; $r->save(); $n++; }
                            \Filament\Notifications\Notification::make()->title("{$n} desativados")->success()->send();
                        }),
                    // NOV-128: alterar preço em % (positivo aumenta, negativo reduz)
                    Tables\Actions\BulkAction::make('alterarPrecoLote')
                        ->label('Alterar preço em %')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('info')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('pct')
                                ->label('Variação em %')
                                ->numeric()
                                ->required()
                                ->helperText('Ex: 10 = aumenta 10%, -5 = reduz 5%'),
                            \Filament\Forms\Components\Select::make('field')
                                ->label('Campo')
                                ->options(['price' => 'Preço de venda', 'cost' => 'Custo'])
                                ->default('price')
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $pct = (float) $data['pct'];
                            $field = $data['field'] ?? 'price';
                            $n = 0;
                            foreach ($records as $r) {
                                $cur = (float) ($r->{$field} ?? 0);
                                if ($cur <= 0) continue;
                                $r->{$field} = round($cur * (1 + $pct / 100), 2);
                                $r->save();
                                $n++;
                            }
                            \Filament\Notifications\Notification::make()->title('Preços alterados')->body("{$n} produtos atualizados ({$pct}% em {$field}).")->success()->send();
                        }),
                    // NOV-128: alterar estoque mínimo em lote
                    Tables\Actions\BulkAction::make('alterarEstoqueMinimoLote')
                        ->label('Alterar estoque mínimo')
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->color('gray')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('threshold')
                                ->label('Novo limite')
                                ->numeric()
                                ->required()
                                ->minValue(0),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $threshold = (int) $data['threshold'];
                            $n = 0;
                            foreach ($records as $r) {
                                \App\Models\Inventory::where('product_id', $r->id)->update(['stock_alert_threshold' => $threshold]);
                                $n++;
                            }
                            \Filament\Notifications\Notification::make()->title('Estoque mínimo atualizado')->body("{$n} produtos -> {$threshold} unidades")->success()->send();
                        }),
                    // NOV-128: enfileirar publicação em marketplace
                    Tables\Actions\BulkAction::make('publicarLote')
                        ->label('Publicar em marketplace')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('primary')
                        ->form([
                            \Filament\Forms\Components\Select::make('marketplace')
                                ->label('Marketplace alvo')
                                ->options([
                                    'mercadolivre' => 'Mercado Livre',
                                    'shopee' => 'Shopee',
                                ])
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $mp = $data['marketplace'];
                            $n = 0;
                            foreach ($records as $r) {
                                if (class_exists(\App\Jobs\ProcessProductListingJob::class)) {
                                    \App\Jobs\ProcessProductListingJob::dispatch($r->id, $mp);
                                    $n++;
                                }
                            }
                            \Filament\Notifications\Notification::make()->title('Publicação enfileirada')->body("{$n} produtos enviados para {$mp}")->success()->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * MUL-226-12: colunas do modo Lista (tabela tradicional).
     */
    protected static function getListColumns(): array
    {
        return [
            Tables\Columns\ImageColumn::make('cover_image')
                ->label('Foto')
                ->getStateUsing(fn (Product $record) => self::resolveCoverUrl($record))
                ->defaultImageUrl(fn (Product $record) => 'https://ui-avatars.com/api/?name=' . urlencode(substr($record->sku ?? '?', 0, 2)) . '&background=1e293b&color=94a3b8&size=120')
                ->size(60)
                ->square(),

            Tables\Columns\TextColumn::make('supplier.company_name')
                ->label('Fornecedor')
                ->searchable()
                ->sortable()
                ->hidden(fn() => auth()->user()?->role !== 'super_admin'),

            Tables\Columns\TextColumn::make('name')
                ->label('Produto')
                ->sortable()
                ->searchable()
                ->weight('bold')
                ->description(fn(Product $record) => '#' . $record->sku)
                ->limit(50)
                ->wrap(),

            Tables\Columns\TextColumn::make('price')
                ->label('Preço')
                ->money('BRL')
                ->sortable()
                ->color('success'),

            Tables\Columns\TextColumn::make('cost')
                ->label('Custo')
                ->money('BRL')
                ->sortable(),

            Tables\Columns\TextColumn::make('estoque_total')
                ->label('Estoque')
                ->getStateUsing(fn (Product $record) => (int) \App\Models\Inventory::where('product_id', $record->id)->sum('quantity'))
                ->badge()
                ->color(fn ($state) => $state > 10 ? 'success' : ($state > 0 ? 'warning' : 'danger'))
                ->alignCenter(),

            // MUL-226-13/14: estoque efetivamente publicado nos marketplaces (real + reserva/inflação globais)
            Tables\Columns\TextColumn::make('estoque_publicado')
                ->label('Publicado')
                ->getStateUsing(fn (Product $record) => $record->publishedStock())
                ->badge()
                ->color(fn ($state) => $state > 0 ? 'info' : 'danger')
                ->alignCenter()
                ->toggleable()
                ->tooltip('Estoque publicado no ML/Shopee: real + regras globais de reserva (piso→0) e inflação (soma). Configurar em Estoque & Remessas → Regras de Estoque.'),

            Tables\Columns\IconColumn::make('is_active')
                ->label('Cadastro Ativo')
                ->tooltip('Toggle is_active do produto. Nao significa que tem estoque.')
                ->boolean(),
        ];
    }

    /**
     * MUL-226-12: colunas do modo Grade (cards) — default da listagem.
     */
    protected static function getGridColumns(): array
    {
        return [
            Tables\Columns\Layout\Stack::make([
                Tables\Columns\ImageColumn::make('cover_image')
                    ->label('')
                    ->getStateUsing(fn (Product $record) => self::resolveCoverUrl($record))
                    ->defaultImageUrl(fn (Product $record) => 'https://ui-avatars.com/api/?name=' . urlencode(substr($record->sku ?? '?', 0, 2)) . '&background=1e293b&color=94a3b8&size=240')
                    ->height(160)
                    ->extraImgAttributes(['style' => 'width:100%;object-fit:cover;border-radius:10px;']),

                Tables\Columns\TextColumn::make('name')
                    ->label('Produto')
                    ->searchable()
                    ->weight('bold')
                    ->limit(60)
                    ->wrap(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->formatStateUsing(fn ($state) => '#' . $state)
                    ->color('gray')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::ExtraSmall),

                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->badge()
                    ->color('info')
                    ->hidden(fn() => auth()->user()?->role !== 'super_admin'),

                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('price')
                        ->label('Preço')
                        ->money('BRL')
                        ->weight('bold')
                        ->color('success'),

                    Tables\Columns\TextColumn::make('estoque_total')
                        ->label('Estoque')
                        ->getStateUsing(fn (Product $record) => (int) \App\Models\Inventory::where('product_id', $record->id)->sum('quantity'))
                        ->badge()
                        ->formatStateUsing(fn ($state) => "Estoque: {$state}")
                        ->color(fn ($state) => $state > 10 ? 'success' : ($state > 0 ? 'warning' : 'danger')),
                ]),
            ])->space(2),
        ];
    }

    /**
     * Resolve a URL da imagem de capa (is_cover primeiro, senão menor position).
     */
    protected static function resolveCoverUrl(Product $record): ?string
    {
        $media = $record->media()
            ->where('type', 'image')
            ->orderByDesc('is_cover')
            ->orderBy('position')
            ->first();
        if (!$media) return null;
        $url = $media->url;
        if ($url && !str_starts_with($url, 'http') && str_starts_with($url, '/storage/')) {
            $url = asset($url);
        }
        return $url;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\KitItemsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['supplier', 'media', 'category']);
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        }
        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
