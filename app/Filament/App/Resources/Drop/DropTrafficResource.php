<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropTrafficResource\Pages;
use App\Models\Drop\DropTrackingPixel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropTrafficResource extends Resource
{
    protected static ?string $model = DropTrackingPixel::class;
    protected static ?string $slug = 'drop/trafego';
    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 6;
    protected static ?string $navigationLabel = 'Trafego & Pixels';
    protected static ?string $modelLabel = 'Pixel de Rastreamento';
    protected static ?string $pluralModelLabel = 'Trafego & Pixels';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'super_admin') {
            return $query;
        }

        $clientId = $user->client?->id;
        if ($clientId) {
            $plan = $user->client->subscription?->plan ?? null;
            if ($plan && isset($plan->has_drop_internacional) && !$plan->has_drop_internacional) {
                Notification::make()
                    ->title('Modulo Drop Internacional')
                    ->body('Seu plano atual nao inclui o modulo Drop Internacional. Fale com o suporte para fazer upgrade.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuracao do Pixel')
                    ->schema([
                        Forms\Components\Select::make('platform')
                            ->label('Plataforma')
                            ->options([
                                'meta'    => 'Meta (Facebook/Instagram)',
                                'google'  => 'Google Ads',
                                'tiktok'  => 'TikTok',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('pixel_id')
                            ->label('ID do Pixel')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('access_token')
                            ->label('Access Token (CAPI)')
                            ->password()
                            ->revealable()
                            ->nullable()
                            ->maxLength(500)
                            ->helperText('Necessario para conversoes via API do servidor (CAPI).'),
                        Forms\Components\TextInput::make('test_event_code')
                            ->label('Codigo de Teste')
                            ->nullable()
                            ->maxLength(100)
                            ->helperText('Opcional. Use durante testes para filtrar eventos no painel da plataforma.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Pixel Ativo')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('Plataforma')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'meta'   => 'primary',
                        'google' => 'success',
                        'tiktok' => 'warning',
                        default  => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'meta'   => 'Meta',
                        'google' => 'Google',
                        'tiktok' => 'TikTok',
                        default  => $state,
                    }),
                Tables\Columns\TextColumn::make('pixel_id')
                    ->label('ID do Pixel')
                    ->copyable()
                    ->copyMessage('ID copiado!')
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Ativo'),
                Tables\Columns\TextColumn::make('test_event_code')
                    ->label('Cod. Teste')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Plataforma')
                    ->options([
                        'meta'   => 'Meta',
                        'google' => 'Google',
                        'tiktok' => 'TikTok',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Excluir'),
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
            'index'  => Pages\ListDropTrackingPixels::route('/'),
            'create' => Pages\CreateDropTrackingPixel::route('/create'),
            'edit'   => Pages\EditDropTrackingPixel::route('/{record}/edit'),
        ];
    }
}
