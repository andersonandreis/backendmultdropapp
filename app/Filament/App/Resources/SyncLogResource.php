<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SyncLogResource\Pages;
use App\Filament\App\Resources\SyncLogResource\RelationManagers;
use App\Models\SyncLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Diagnostico de Erros';
    protected static ?string $modelLabel = 'Erro de Integracao';
    protected static ?string $pluralModelLabel = 'Diagnostico de Erros';


    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        // Mostrar apenas erros com mensagem (comportamento original)
        $query->where('status', 'error')->whereNotNull('error_message');

        if ($user->role === 'super_admin') {
            return $query;
        }

        // Filtrar apenas logs das contas de marketplace do lojista logado
        $clientId = $user->client?->id;
        if ($clientId) {
            $accountIds = \App\Models\MarketplaceAccount::where('client_id', $clientId)->pluck('id');
            return $query->whereIn('marketplace_account_id', $accountIds);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Diagnóstico da Integração')
                    ->description('Veja exatamente o que a plataforma recusou e corrija.')
                    ->schema([
                        Forms\Components\TextInput::make('platform')
                            ->label('Plataforma')
                            ->disabled()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('action')
                            ->label('Ação Tentada')
                            ->disabled()
                            ->columnSpan(1),
                        Forms\Components\Textarea::make('error_message')
                            ->label('Motivo da Recusa (Shopee / Bling)')
                            ->disabled()
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Dados Avançados (Devs)')
                    ->schema([
                        Forms\Components\KeyValue::make('request_payload')
                            ->label('O que enviamos')
                            ->disabled(),
                        Forms\Components\KeyValue::make('response_payload')
                            ->label('A resposta técnica crua')
                            ->disabled(),
                    ])->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('Canal')
                    ->badge()
                    ->colors([
                        'warning' => 'shopee',
                        'success' => 'mercadolivre',
                        'primary' => 'bling',
                    ]),
                Tables\Columns\TextColumn::make('action')
                    ->label('Operação')
                    ->searchable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('O que você precisa corrigir')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver Detalhes'),
                Tables\Actions\Action::make('corrigir')
                    ->label('Corrigir Agora (Guia)')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('success')
                    ->modalHeading('Assistente de Resolução')
                    ->modalSubmitActionLabel('Entendi, vou corrigir')
                    ->modalContent(fn(SyncLog $record) => new HtmlString('<b>Analise o motivo da falha:</b><br><br><span style="color:red;">' . $record->error_message . '</span><br><br>Vá até o seu "Catálogo do Fornecedor" ou "Meus Pedidos" e ajuste a informação faltante (Ex: Preencher Marca, Ajustar Peso, ou Corrigir CEP). Após salvar, nossa automação reenviará para a plataforma!'))
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            // Remove create/edit since this is read-only resolver
        ];
    }
}
