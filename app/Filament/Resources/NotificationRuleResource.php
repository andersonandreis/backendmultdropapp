<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationRuleResource\Pages;
use App\Models\NotificationRule;
use App\Models\SyncLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * MUL-226-03: Central de Notificações do painel do fornecedor.
 * CRUD de regras (categoria × evento × janela × canal) — quem envia notificação
 * consulta NotificationRule::isAllowedNow($cat, $event, $supplierId).
 */
class NotificationRuleResource extends Resource
{
    protected static ?string $model = NotificationRule::class;

    protected static ?string $slug = 'notifications';

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Central de Notificações';

    protected static ?string $modelLabel = 'Regra de Notificação';

    protected static ?string $pluralModelLabel = 'Central de Notificações';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $query->where(function ($q) use ($user) {
                $q->where('supplier_id', $user->supplier->id)->orWhereNull('supplier_id');
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('O que notificar')
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('Categoria')
                        ->options(NotificationRule::CATEGORIES)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('event', null)),

                    Forms\Components\Select::make('event')
                        ->label('Evento')
                        ->options(fn (Get $get) => NotificationRule::EVENTS[$get('category')] ?? [])
                        ->required()
                        ->searchable(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Quando notificar')
                ->description('Regras respeitam essa janela — fora dela a notificação não é enviada.')
                ->schema([
                    Forms\Components\CheckboxList::make('days_of_week')
                        ->label('Dias da semana')
                        ->options(NotificationRule::DAYS)
                        ->default(array_keys(NotificationRule::DAYS))
                        ->required()
                        ->columns(4)
                        ->helperText('Deixe todos marcados pra notificar todo dia.'),

                    Forms\Components\TimePicker::make('time_start')
                        ->label('Horário inicial')
                        ->seconds(false)
                        ->default('09:00')
                        ->required(),

                    Forms\Components\TimePicker::make('time_end')
                        ->label('Horário final')
                        ->seconds(false)
                        ->default('18:00')
                        ->required(),
                ])
                ->columns(3),

            Forms\Components\Section::make('Como notificar')
                ->schema([
                    Forms\Components\Select::make('channel')
                        ->label('Canal')
                        ->options(NotificationRule::CHANNELS)
                        ->default('email')
                        ->required(),

                    Forms\Components\Toggle::make('enabled')
                        ->label('Regra ativa')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->formatStateUsing(fn ($state) => NotificationRule::CATEGORIES[$state] ?? $state),

                Tables\Columns\TextColumn::make('event')
                    ->label('Evento')
                    ->formatStateUsing(function ($state, NotificationRule $r) {
                        return NotificationRule::EVENTS[$r->category][$state] ?? $state;
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('days_of_week')
                    ->label('Dias')
                    ->formatStateUsing(function ($state) {
                        $dias = is_array($state) ? $state : (json_decode($state ?? '[]', true) ?: []);
                        if (count($dias) >= 7) {
                            return 'Todos';
                        }

                        return collect($dias)->map(fn ($d) => NotificationRule::DAYS[$d] ?? $d)->join(', ');
                    }),

                Tables\Columns\TextColumn::make('time_start')
                    ->label('Início')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('time_end')
                    ->label('Fim')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn ($state) => NotificationRule::CHANNELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'email' => 'info',
                        'push'  => 'warning',
                        'both'  => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('enabled')
                    ->label('Ativa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(NotificationRule::CATEGORIES),

                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Ativas'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Nenhuma regra cadastrada')
            ->emptyStateDescription('Sem regras aqui, o padrão é notificar sempre. Cadastre uma regra pra restringir dias/horários por evento.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListNotificationRules::route('/'),
            'create' => Pages\CreateNotificationRule::route('/create'),
            'edit'   => Pages\EditNotificationRule::route('/{record}/edit'),
        ];
    }

    public static function auditChange(NotificationRule $rule, string $action, array $extra = []): void
    {
        SyncLog::create([
            'syncable_type'   => NotificationRule::class,
            'syncable_id'     => $rule->id,
            'platform'        => 'internal',
            'action'          => 'notification_rule_' . $action,
            'direction'       => 'internal',
            'status'          => 'success',
            'request_payload' => json_encode(array_merge([
                'category'   => $rule->category,
                'event'      => $rule->event,
                'user_id'    => auth()->id(),
                'user_email' => auth()->user()?->email,
                'origem'     => 'admin/notifications',
            ], $extra), JSON_UNESCAPED_UNICODE),
        ]);

        NotificationRule::forgetCacheFor($rule->category, $rule->event, $rule->supplier_id);

        Notification::make()
            ->title('Regra ' . ($action === 'create' ? 'criada' : ($action === 'update' ? 'atualizada' : 'removida')))
            ->body(NotificationRule::CATEGORIES[$rule->category] . ' → ' . (NotificationRule::EVENTS[$rule->category][$rule->event] ?? $rule->event))
            ->success()
            ->send();
    }
}
