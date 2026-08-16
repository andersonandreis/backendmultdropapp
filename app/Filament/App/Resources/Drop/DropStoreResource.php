<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropStoreResource\Pages;
use App\Models\Drop\DropStore;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropStoreResource extends Resource
{
    protected static ?string $model = DropStore::class;
    protected static ?string $slug = 'drop/lojas';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Minha Loja';
    protected static ?string $modelLabel = 'Loja Drop';
    protected static ?string $pluralModelLabel = 'Lojas Drop';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) return $query->whereRaw('1 = 0');
        if ($user->role === 'super_admin') return $query;

        $clientId = $user->client?->id;
        return $clientId
            ? $query->where('client_id', $clientId)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        if ($user->role === 'super_admin') return true;
        if (!$user->client) {
            Notification::make()
                ->title('Conta nao configurada')
                ->body('Seu cadastro ainda nao esta ativo. Entre em contato com o suporte.')
                ->warning()->persistent()->send();
            return false;
        }
        return true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Tipo de Loja')
                ->schema([
                    Forms\Components\Select::make('platform')
                        ->label('Plataforma')
                        ->options([
                            'shopify' => 'Shopify — conectar loja existente',
                            'native'  => 'Loja Nativa — criada pelo sistema',
                        ])
                        ->default('shopify')
                        ->required()
                        ->live()
                        ->helperText('Escolha Shopify se ja tem uma loja, ou Loja Nativa para criar do zero.'),
                ])->columns(1),

            Forms\Components\Section::make('Configuracoes Shopify')
                ->schema([
                    Forms\Components\TextInput::make('shop_domain')
                        ->label('Dominio Shopify')
                        ->placeholder('minhaloja.myshopify.com')
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->required(fn (Get $get) => $get('platform') === 'shopify'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending'     => 'Pendente',
                            'active'      => 'Ativa',
                            'inactive'    => 'Inativa',
                            'uninstalled' => 'Desinstalada',
                        ])
                        ->default('pending'),
                    Forms\Components\TextInput::make('currency')
                        ->label('Moeda')
                        ->placeholder('USD')
                        ->default('USD')
                        ->maxLength(10),
                ])->columns(2)
                ->visible(fn (Get $get) => $get('platform') === 'shopify'),

            Forms\Components\Section::make('Configuracoes da Loja Nativa')
                ->schema([
                    Forms\Components\TextInput::make('store_slug')
                        ->label('Slug da Loja')
                        ->placeholder('minha-loja')
                        ->helperText('URL: loja.hubai.io/seu-slug — apenas letras minusculas, numeros e hifens.')
                        ->regex('/^[a-z0-9\-]+$/')
                        ->minLength(3)
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->required(fn (Get $get) => $get('platform') === 'native'),
                    Forms\Components\TextInput::make('store_display_name')
                        ->label('Nome da Loja')
                        ->placeholder('Minha Super Loja')
                        ->maxLength(100)
                        ->required(fn (Get $get) => $get('platform') === 'native'),
                    Forms\Components\ColorPicker::make('primary_color')
                        ->label('Cor Principal')
                        ->default('#3B82F6'),
                    Forms\Components\TextInput::make('custom_domain')
                        ->label('Dominio Proprio (opcional)')
                        ->placeholder('www.minhaloja.com.br')
                        ->helperText('Deixe vazio para usar loja.hubai.io/slug')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('logo_url')
                        ->label('URL do Logo')
                        ->placeholder('https://...')
                        ->url()
                        ->maxLength(500),
                    Forms\Components\TextInput::make('banner_url')
                        ->label('URL do Banner (opcional)')
                        ->placeholder('https://...')
                        ->url()
                        ->maxLength(500),
                    Forms\Components\Toggle::make('is_published')
                        ->label('Loja Publicada')
                        ->helperText('Desative para tirar a loja do ar temporariamente.')
                        ->columnSpanFull(),
                ])->columns(2)
                ->visible(fn (Get $get) => $get('platform') === 'native'),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn ($state) => $state === 'shopify' ? 'success' : 'info')
                    ->formatStateUsing(fn ($state) => $state === 'shopify' ? 'Shopify' : 'Nativa'),
                Tables\Columns\TextColumn::make('shop_domain')
                    ->label('Dominio / Slug')
                    ->formatStateUsing(fn ($state, $record) =>
                        $record->platform === 'native'
                            ? ($record->store_slug ?? $state)
                            : $state
                    )
                    ->searchable()->copyable()->sortable(),
                Tables\Columns\TextColumn::make('store_display_name')
                    ->label('Nome')
                    ->formatStateUsing(fn ($state, $record) =>
                        $record->platform === 'native' ? ($state ?? '-') : ($record->shop_name ?? '-')
                    )
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'active'   => 'success',
                        'pending'  => 'warning',
                        'inactive' => 'danger',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'active'      => 'Ativa',
                        'pending'     => 'Pendente',
                        'inactive'    => 'Inativa',
                        'uninstalled' => 'Desinstalada',
                        default       => $state,
                    }),
                Tables\Columns\IconColumn::make('is_published')
                    ->label('No ar')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('currency')
                    ->label('Moeda')->badge()->color('info'),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Ultima Sync')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Tipo')
                    ->options(['shopify' => 'Shopify', 'native' => 'Nativa']),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['active' => 'Ativa', 'pending' => 'Pendente', 'inactive' => 'Inativa']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Editar'),
                Tables\Actions\Action::make('open_store')
                    ->label('Abrir Loja')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('success')
                    ->url(fn (DropStore $record) =>
                        $record->isNative()
                            ? $record->getPublicUrl()
                            : 'https://' . $record->shop_domain
                    )
                    ->openUrlInNewTab()
                    ->visible(fn (DropStore $record) => $record->status === 'active'),
                Tables\Actions\Action::make('publish_toggle')
                    ->label(fn (DropStore $record) => $record->is_published ? 'Despublicar' : 'Publicar')
                    ->icon(fn (DropStore $record) => $record->is_published
                        ? 'heroicon-o-eye-slash'
                        : 'heroicon-o-eye')
                    ->color(fn (DropStore $record) => $record->is_published ? 'warning' : 'success')
                    ->visible(fn (DropStore $record) => $record->platform === 'native')
                    ->action(function (DropStore $record) {
                        if (!$record->is_published && !$record->defaultGateway) {
                            Notification::make()
                                ->title('Gateway necessario')
                                ->body('Adicione pelo menos um gateway de pagamento antes de publicar.')
                                ->warning()->send();
                            return;
                        }
                        $record->update([
                            'is_published' => !$record->is_published,
                            'published_at' => !$record->is_published ? now() : $record->published_at,
                        ]);
                        Notification::make()
                            ->title($record->is_published ? 'Loja publicada!' : 'Loja despublicada.')
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('health_check')
                    ->label('Verificar saude')
                    ->icon('heroicon-o-heart')
                    ->color('warning')
                    ->visible(fn (DropStore $record) => $record->platform === 'shopify')
                    ->action(function (DropStore $record) {
                        try {
                            $service = app(\App\Services\Drop\Shopify\ShopifyConnectionService::class);
                            $result  = $service->healthCheck($record);
                            Notification::make()
                                ->title($result['healthy'] ? 'Loja saudavel' : 'Problema detectado')
                                ->body($result['message'] ?? '')
                                ->status($result['healthy'] ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Erro')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()->iconButton()->tooltip('Excluir'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index'  => Pages\ListDropStores::route('/'),
            'create' => Pages\CreateDropStore::route('/create'),
            'edit'   => Pages\EditDropStore::route('/{record}/edit'),
        ];
    }
}
