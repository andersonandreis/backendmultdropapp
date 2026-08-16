<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $slug = 'usuarios';

    protected static ?string $modelLabel = 'Usuário';
    protected static ?string $pluralModelLabel = 'Usuários';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Configurações';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados do Usuário')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->dehydrateStateUsing(fn($state) => Hash::make($state))
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create')
                            ->maxLength(255),
                        Forms\Components\DateTimePicker::make('email_verified_at')
                            ->label('E-mail Verificado em'),
                    ])->columns(2),

                Forms\Components\Section::make('Permissões e Acesso')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Papel')
                            ->options([
                                'super_admin' => 'Super Admin',
                                'supplier' => 'Logista / Fornecedor',
                                'client' => 'Cliente (Seller)',
                            ])
                            ->required()
                            ->default('client')
                            ->live()
                            ->native(false)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ])->columns(1),

                Forms\Components\Section::make('Plano e Assinatura')
                    ->schema([
                        Forms\Components\Select::make('plan_id')
                            ->label('Plano')
                            ->options(Plan::where('is_active', true)->pluck('name', 'id'))
                            ->placeholder('Sem plano')
                            ->native(false),
                        Forms\Components\Select::make('subscription_status')
                            ->label('Status da Assinatura')
                            ->options([
                                'active' => 'Ativa',
                                'trialing' => 'Trial',
                                'past_due' => 'Pagamento Pendente',
                                'cancelled' => 'Cancelada',
                                'expired' => 'Expirada',
                            ])
                            ->default('active')
                            ->visible(fn(Forms\Get $get) => filled($get('plan_id')))
                            ->native(false),
                        Forms\Components\Select::make('subscription_payment_method')
                            ->label('Método de Pagamento')
                            ->options([
                                'credit_card' => 'Cartão de Crédito',
                                'boleto' => 'Boleto',
                                'pix' => 'Pix',
                                'manual' => 'Manual (Admin)',
                            ])
                            ->default('manual')
                            ->visible(fn(Forms\Get $get) => filled($get('plan_id')))
                            ->native(false),
                        Forms\Components\DateTimePicker::make('subscription_period_end')
                            ->label('Válido até')
                            ->visible(fn(Forms\Get $get) => filled($get('plan_id'))),
                    ])
                    ->columns(2)
                    ->description('Atribua um plano ao usuário para testar painéis e funcionalidades.')
                    ->visible(fn(Forms\Get $get) => $get('role') === 'client'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Papel')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'super_admin' => 'Super Admin',
                        'supplier' => 'Fornecedor',
                        'client' => 'Cliente',
                        default => $state,
                    })
                    ->color(fn(string $state) => match ($state) {
                        'super_admin' => 'danger',
                        'supplier' => 'warning',
                        'client' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('client.subscriptions.plan.name')
                    ->label('Plano')
                    ->default('—')
                    ->getStateUsing(function (User $record) {
                        $sub = $record->client?->subscriptions()
                            ->whereIn('status', ['active', 'trialing'])
                            ->latest()
                            ->first();
                        return $sub?->plan?->name ?? '—';
                    }),
                Tables\Columns\TextColumn::make('email_verified_at')->label('Verificado em')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Criado em')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Papel')
                    ->options([
                        'super_admin' => 'Super Admin',
                        'supplier' => 'Fornecedor',
                        'client' => 'Cliente',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Ativo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('add_days')
                    ->label('Adicionar Dias')
                    ->icon('heroicon-o-calendar-days')
                    ->color('success')
                    ->visible(fn ($record) => $record->role === 'client')
                    ->form([
                        Forms\Components\TextInput::make('days')
                            ->label('Dias a adicionar')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(365),
                    ])
                    ->action(function ($record, array $data): void {
                        $client = $record->client;
                        if (!$client) return;
                        $subscription = $client->subscriptions()->latest()->first();
                        if ($subscription) {
                            $end = $subscription->current_period_end ?? now();
                            $trialEnd = $subscription->trial_ends_at;
                            $subscription->update([
                                'current_period_end' => Carbon::parse($end)->addDays($data['days']),
                                'trial_ends_at' => $trialEnd ? Carbon::parse($trialEnd)->addDays($data['days']) : null,
                                'status' => 'active',
                            ]);
                        } else {
                            Subscription::create([
                                'client_id' => $client->id,
                                'status' => 'active',
                                'payment_method' => 'manual',
                                'current_period_start' => now(),
                                'current_period_end' => now()->addDays($data['days']),
                            ]);
                        }
                        Notification::make()->title("{$data['days']} dias adicionados.")->success()->send();
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label('Suspender')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn ($record) => $record->role === 'client')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $client = $record->client;
                        if ($client) {
                            $client->subscriptions()->update(['status' => 'cancelled']);
                        }
                        Notification::make()->title('Acesso suspenso.')->warning()->send();
                    }),

                Tables\Actions\Action::make('reactivate')
                    ->label('Reativar')
                    ->icon('heroicon-o-play-circle')
                    ->color('primary')
                    ->visible(fn ($record) => $record->role === 'client')
                    ->action(function ($record): void {
                        $client = $record->client;
                        if (!$client) return;
                        $subscription = $client->subscriptions()->latest()->first();
                        if ($subscription) {
                            $subscription->update([
                                'status' => 'active',
                                'current_period_end' => now()->addDays(30),
                            ]);
                        } else {
                            Subscription::create([
                                'client_id' => $client->id,
                                'status' => 'active',
                                'payment_method' => 'manual',
                                'current_period_start' => now(),
                                'current_period_end' => now()->addDays(30),
                            ]);
                        }
                        Notification::make()->title('Acesso reativado.')->success()->send();
                    }),

                Tables\Actions\Action::make('impersonate')
                    ->label('Acessar como cliente')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn ($record) => $record->role === 'client')
                    ->requiresConfirmation()
                    ->modalHeading('Acessar painel como este cliente?')
                    ->modalDescription('Voce vera o painel exatamente como o cliente ve. Um link com validade de 15 minutos sera gerado.')
                    ->action(function ($record) {
                        $admin = auth()->user();
                        $token = (string) \Illuminate\Support\Str::uuid();
                        $expiresAt = now()->addMinutes(15);
                        \Illuminate\Support\Facades\DB::table('impersonation_logs')->insert([
                            'token'          => $token,
                            'admin_id'       => $admin->id,
                            'target_user_id' => $record->id,
                            'ip_address'     => request()->ip(),
                            'expires_at'     => $expiresAt,
                            'created_at'     => now(),
                        ]);
                        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://app.hubai.io'));
                        $url = rtrim($frontendUrl, '/') . '/impersonate?token=' . $token;
                        Notification::make()
                            ->title('Link de impersonacao gerado!')
                            ->body('Valido por 15 minutos: ' . $url)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
