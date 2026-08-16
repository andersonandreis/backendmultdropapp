<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierApiTokenResource\Pages;
use App\Models\SupplierApiToken;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** NOV-137 — Tokens de API pessoais do supplier. */
class SupplierApiTokenResource extends Resource
{
    protected static ?string $model = SupplierApiToken::class;
    protected static ?string $slug = 'tokens-api';
    protected static ?string $modelLabel = 'Token';
    protected static ?string $pluralModelLabel = 'Tokens de API';
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Equipe & Acessos';
    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')
                ->relationship('supplier', 'company_name')->required()
                ->visible(fn () => auth()->user()?->role === 'super_admin'),
            Forms\Components\TextInput::make('name')->label('Nome do token')->required()->maxLength(100)
                ->placeholder('Ex: Integração Zapier'),
            Forms\Components\DateTimePicker::make('expires_at')->label('Expira em (opcional)'),
            Forms\Components\CheckboxList::make('abilities')->label('Permissões')
                ->options([
                    '*'              => 'Acesso total',
                    'orders.read'    => 'Ler pedidos',
                    'orders.write'   => 'Criar/atualizar pedidos',
                    'products.read'  => 'Ler produtos',
                    'products.write' => 'Gerenciar produtos',
                    'inventory.write'=> 'Gerenciar estoque',
                    'webhooks.read'  => 'Ver webhooks',
                ])->columns(2)->default(['orders.read', 'products.read']),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('prefix')->label('Prefixo')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('last_used_at')->label('Último uso')
                    ->dateTime('d/m/Y H:i')->placeholder('Nunca'),
                Tables\Columns\TextColumn::make('expires_at')->label('Expira')
                    ->dateTime('d/m/Y')->placeholder('Não expira'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('gerar')
                    ->label('Gerar novo token')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(100),
                        Forms\Components\DateTimePicker::make('expires_at')->label('Expira em (opcional)'),
                    ])
                    ->action(function (array $data) {
                        $supplierId = auth()->user()->supplier?->id;
                        if (!$supplierId) {
                            Notification::make()->title('Sem supplier vinculado')->danger()->send();
                            return;
                        }
                        $out = SupplierApiToken::generate($supplierId, $data['name'], ['*'], auth()->id());
                        if (!empty($data['expires_at'])) {
                            $out['model']->expires_at = $data['expires_at'];
                            $out['model']->save();
                        }
                        Notification::make()
                            ->title('Token criado — copie agora! Ele não será mostrado novamente.')
                            ->body($out['plain'])
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revogar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($r) => $r->active)
                    ->action(fn ($record) => $record->update(['active' => false])),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierApiTokens::route('/'),
        ];
    }
}
