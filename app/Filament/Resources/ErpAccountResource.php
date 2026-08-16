<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ErpAccountResource\Pages;
use App\Models\ErpAccount;
use App\Models\Supplier;
use Filament\Forms;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;

class ErpAccountResource extends Resource
{
    protected static ?string $model = ErpAccount::class;
    protected static ?string $slug = 'contas-erp';
    protected static ?string $modelLabel = 'Conta ERP';
    protected static ?string $pluralModelLabel = 'Conexões ERP';

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Integrações';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('supplier_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Conta ERP é vinculada ao fornecedor (não ao lojista).'),

                Forms\Components\TextInput::make('account_name')
                    ->label('Apelido da conta')
                    ->maxLength(120)
                    ->default('Bling ERP'),

                Forms\Components\Select::make('platform')
                    ->label('Sistema ERP')
                    ->options([
                        'bling' => 'Bling ERP',
                        'tiny'  => 'Tiny ERP',
                        'omie'  => 'Omie',
                        'bsoft' => 'BSoft',
                    ])
                    ->required()
                    ->default('bling'),

                Forms\Components\Select::make('api_version')
                    ->label('Versão da API')
                    ->options([
                        'v2' => 'v2 (Legado)',
                        'v3' => 'v3 (Atual)',
                    ])
                    ->required()
                    ->default('v3'),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Precisa reconectar',
                        'inactive'     => 'Inativo',
                        'error'        => 'Com erro',
                    ])
                    ->required()
                    ->default('inactive'),

                Forms\Components\TextInput::make('api_key')
                    ->label('Access Token')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('Preenchido automaticamente pelo OAuth. Edite só em caso de migração manual.'),

                Forms\Components\TextInput::make('refresh_token')
                    ->label('Refresh Token')
                    ->password()
                    ->revealable()
                    ->columnSpanFull(),

                Forms\Components\DateTimePicker::make('token_expires_at')
                    ->label('Token expira em'),

                Forms\Components\DateTimePicker::make('last_sync_at')
                    ->label('Última sincronização')
                    ->disabled(),

                // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                Forms\Components\Select::make('client_id')
                    ->label('Lojista (opcional — legado)')
                    ->relationship('client', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->company_name ?? ('Client #'.$record->id))
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Apenas para contas ERP antigas vinculadas a um lojista.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('account_name')
                    ->label('Conta')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('platform')
                    ->label('Plataforma')
                    ->badge(),

                Tables\Columns\TextColumn::make('api_version')
                    ->label('API')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'warning' => 'needs_reauth',
                        'gray'    => 'inactive',
                        'danger'  => 'error',
                    ]),
                // MUL-144: badge saude do token
                Tables\Columns\TextColumn::make('token_health')
                    ->label('Token')
                    ->badge()
                    ->state(function (ErpAccount $record): string {
                        if (! $record->token_expires_at) { return 'Sem validade'; }
                        if ($record->token_expires_at->isPast()) { return 'Expirado'; }
                        if ($record->token_expires_at->isBefore(now()->addHours(6))) { return 'Expirando'; }
                        return 'Valido';
                    })
                    ->color(function (ErpAccount $record): string {
                        if (! $record->token_expires_at) { return 'gray'; }
                        if ($record->token_expires_at->isPast()) { return 'danger'; }
                        if ($record->token_expires_at->isBefore(now()->addHours(6))) { return 'warning'; }
                        return 'success';
                    }),

                Tables\Columns\TextColumn::make('token_expires_at')
                    ->label('Token expira')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Última sync')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('Nunca'),

                Tables\Columns\TextColumn::make('client.company_name')
                    ->label('Lojista (legado)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label('Plataforma')
                    ->options([
                        'bling' => 'Bling',
                        'tiny'  => 'Tiny',
                        'omie'  => 'Omie',
                        'bsoft' => 'BSoft',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Precisa reconectar',
                        'inactive'     => 'Inativo',
                        'error'        => 'Com erro',
                    ]),

                SelectFilter::make('supplier_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),

                // MUL-144: renovar token ERP sem OAuth
                Action::make('renovar_token')
                    ->label('Renovar Token')
                    ->icon('heroicon-o-bolt')
                    ->color('info')
                    ->visible(function (ErpAccount $record): bool {
                        return $record->platform === 'bling' && $record->status === 'active';
                    })
                    ->action(function (ErpAccount $record): void {
                        try {
                            $auth = app(BlingAuthService::class);
                            $auth->getValidTokenForErp($record);
                            $record->refresh();
                            $exp = $record->token_expires_at?->format('d/m/Y H:i') ?? 'N/A';
                            Notification::make()
                                ->title('Token renovado')
                                ->body('Expira em: ' . $exp)
                                ->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Falha ao renovar')
                                ->body(substr($e->getMessage(), 0, 150))
                                ->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Renovar token Bling')
                    ->modalSubmitActionLabel('Renovar agora'),

                Action::make('reconectar')
                    ->label('Reconectar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (ErpAccount $record) => $record->platform === 'bling' && $record->supplier_id)
                    ->url(fn (ErpAccount $record) => url('/api/oauth/bling/redirect') . '?' . http_build_query([
                        'supplier_id'   => $record->supplier_id,
                        'account_type'  => 'supplier_erp',
                        'account_name'  => $record->account_name ?: 'Bling ERP',
                        'source_system' => config('bling.app_tenant', 'multdrop'),
                        'return_url'    => url('/admin/contas-erp'),
                    ]))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListErpAccounts::route('/'),
            'create' => Pages\CreateErpAccount::route('/create'),
            'edit'   => Pages\EditErpAccount::route('/{record}/edit'),
        ];
    }
}
