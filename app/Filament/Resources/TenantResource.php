<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Supplier;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $slug = 'tenants';

    // Labels: "Tenant" internamente; "Whitelabel" e o termo de marketing para usuario
    protected static ?string $modelLabel = 'Tenant (Whitelabel)';
    protected static ?string $pluralModelLabel = 'Tenants (Whitelabels)';
    protected static ?string $navigationLabel = 'Tenants (Whitelabels)';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Supplier Core';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false; // Tenants nunca sao deletados -- usar status=archived
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificacao')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug (identificador unico)')
                        ->required()
                        ->unique(Tenant::class, 'slug', ignoreRecord: true)
                        ->alphaDash()
                        ->maxLength(64)
                        ->helperText('Ex: multdrop.app, fornecefy, meusite. Sem espacos. Nao alterar apos criacao.')
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    Forms\Components\Textarea::make('description')
                        ->label('Descricao')
                        ->maxLength(1000)
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('logo_url')
                        ->label('URL do Logo')
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Configuracao de Acesso')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->required()
                        ->options([
                            Tenant::STATUS_ACTIVE    => 'Ativo',
                            Tenant::STATUS_SUSPENDED => 'Suspenso',
                            Tenant::STATUS_ARCHIVED  => 'Arquivado',
                        ])
                        ->default(Tenant::STATUS_ACTIVE),

                    Forms\Components\Select::make('default_supplier_visibility')
                        ->label('Visibilidade de Fornecedores')
                        ->required()
                        ->options([
                            'scoped' => 'Scoped -- apenas fornecedores vinculados',
                            'all'    => 'All -- todos os fornecedores',
                        ])
                        ->default('scoped')
                        ->helperText('Scoped: usa tabela tenant_supplier. All: ve todos.'),

                    Forms\Components\Toggle::make('write_enabled')
                        ->label('Write Enabled')
                        ->helperText('Habilita criacao de pedidos via API. Manter OFF ate homologar.')
                        ->default(false),

                    Forms\Components\TextInput::make('rate_limit_per_min')
                        ->label('Rate Limit (req/min)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10000)
                        ->default(100)
                        ->suffix('req/min'),

                    Forms\Components\TextInput::make('legacy_empresa_id')
                        ->label('ID Empresa Legado')
                        ->numeric()
                        ->nullable()
                        ->helperText('Preencher apenas se este tenant corresponde a um id_empresa do legado Goolhub.'),
                ]),

            Forms\Components\Section::make('Fornecedores Vinculados')
                ->description('Selecione quais fornecedores este tenant pode acessar. Relevante quando visibilidade = "Scoped".')
                ->schema([
                    Forms\Components\CheckboxList::make('suppliers')
                        ->label('Fornecedores')
                        ->relationship('suppliers', 'company_name')
                        ->searchable()
                        ->columns(3)
                        ->getOptionLabelFromRecordUsing(
                            fn (Supplier $record) => ($record->display_name ?: $record->company_name) . " (id: {$record->id})"
                        ),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('Logo')
                    ->circular()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => Tenant::STATUS_ACTIVE,
                        'warning' => Tenant::STATUS_SUSPENDED,
                        'gray'    => Tenant::STATUS_ARCHIVED,
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Tenant::STATUS_ACTIVE    => 'Ativo',
                        Tenant::STATUS_SUSPENDED => 'Suspenso',
                        Tenant::STATUS_ARCHIVED  => 'Arquivado',
                        default                  => $state,
                    }),

                Tables\Columns\TextColumn::make('default_supplier_visibility')
                    ->label('Visibilidade')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'all'    => 'Todos',
                        'scoped' => 'Scoped',
                        default  => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'all'    => 'warning',
                        'scoped' => 'info',
                        default  => 'gray',
                    }),

                Tables\Columns\IconColumn::make('write_enabled')
                    ->label('Write')
                    ->boolean(),

                Tables\Columns\TextColumn::make('suppliers_count')
                    ->label('Fornecedores')
                    ->counts('suppliers')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate_limit_per_min')
                    ->label('Rate Limit')
                    ->suffix(' req/min')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('legacy_empresa_id')
                    ->label('id_empresa')
                    ->placeholder('--')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('id')
                    ->label('UUID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('slug', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'    => 'Ativo',
                        'suspended' => 'Suspenso',
                        'archived'  => 'Arquivado',
                    ]),
                Tables\Filters\SelectFilter::make('default_supplier_visibility')
                    ->label('Visibilidade')
                    ->options([
                        'all'    => 'Todos',
                        'scoped' => 'Scoped',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('health')
                    ->label('Health')
                    ->icon('heroicon-o-heart')
                    ->color('info')
                    ->action(function (Tenant $record): void {
                        try {
                            Artisan::call('tenant:health', ['--tenant' => $record->slug]);
                            $output = Artisan::output();
                            Notification::make()
                                ->title('Health: ' . $record->name)
                                ->body(mb_strimwidth(strip_tags($output), 0, 200, '...'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao checar health')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('sync_catalog')
                    ->label('Sincronizar Catalogo')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Tenant $record): string => 'Sincronizar catalogo: ' . $record->name)
                    ->modalDescription('Dispara a sincronizacao de produtos para este tenant. Pode levar alguns minutos.')
                    ->action(function (Tenant $record): void {
                        try {
                            if (array_key_exists('tenant:sync-catalog', Artisan::all())) {
                                Artisan::call('tenant:sync-catalog', ['tenant_slug' => $record->slug]);
                                Notification::make()
                                    ->title('Sync iniciado para ' . $record->name)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Comando nao disponivel')
                                    ->body('O comando tenant:sync-catalog ainda nao foi implementado.')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao iniciar sync')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'view'   => Pages\ViewTenant::route('/{record}'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
