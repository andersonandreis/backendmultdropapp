<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateResource\Pages;
use App\Models\Affiliate;
use App\Models\Plan;
use App\Services\AffiliateAccessService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-345: AffiliateResource ampliado.
 * - Filtro por approval_status (Pendentes/Ativos/Suspensos/Rejeitados)
 * - Colunas: nome candidato, codigo, taxa, conversoes, saldo, status aprovacao
 * - Actions: Aprovar, Rejeitar, Suspender, Editar Quotas
 * - Bulk action: Aprovar N de uma vez
 * - Tabs: Pendentes | Ativos | Todos
 */
class AffiliateResource extends Resource
{
    protected static ?string $model = Affiliate::class;

    protected static ?string $slug = 'afiliados';
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Afiliados';
    protected static ?string $navigationLabel = 'Afiliados';
    protected static ?string $modelLabel = 'Afiliado';
    protected static ?string $pluralModelLabel = 'Afiliados';
    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $pending = Affiliate::where('approval_status', 'pending')->count();
        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier', 'admin']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados do Candidato')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Usuário vinculado')
                            ->relationship('user', 'name')
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->nullable(),
                        Forms\Components\TextInput::make('application_name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('application_email')
                            ->label('Email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('application_phone')
                            ->label('Telefone')
                            ->nullable(),
                        Forms\Components\TextInput::make('application_instagram')
                            ->label('Instagram')
                            ->nullable()
                            ->prefix('@'),
                        Forms\Components\TextInput::make('application_tiktok')
                            ->label('TikTok')
                            ->nullable()
                            ->prefix('@'),
                        Forms\Components\Textarea::make('application_motivation')
                            ->label('Por que quer ser afiliado?')
                            ->nullable()
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Aprovação e Status')
                    ->schema([
                        Forms\Components\Select::make('approval_status')
                            ->label('Status de Aprovação')
                            ->options([
                                'pending'   => 'Pendente',
                                'approved'  => 'Aprovado',
                                'rejected'  => 'Rejeitado',
                                'suspended' => 'Suspenso',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('referral_code')
                            ->label('Código de Indicação')
                            ->default(fn () => 'AFF' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)))
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taxa de Comissão')
                            ->numeric()
                            ->suffix('%')
                            ->default(30)
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Motivo de Rejeição')
                            ->nullable()
                            ->visible(fn (Forms\Get $get) => $get('approval_status') === 'rejected')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Quotas e Perks')
                    ->schema([
                        Forms\Components\TextInput::make('max_ai_videos_per_month')
                            ->label('Max vídeos IA / mês')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Forms\Components\Select::make('granted_plan_slug')
                            ->label('Plano grátis concedido')
                            ->options(fn () => Plan::where('is_active', true)->pluck('name', 'slug')->toArray())
                            ->nullable()
                            ->searchable(),
                        Forms\Components\KeyValue::make('perks')
                            ->label('Perks adicionais (JSON)')
                            ->nullable()
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Dados de Pagamento (PIX)')
                    ->schema([
                        Forms\Components\TextInput::make('pix_key')
                            ->label('Chave PIX')
                            ->nullable()
                            ->maxLength(255),
                        Forms\Components\Select::make('pix_type')
                            ->label('Tipo de Chave PIX')
                            ->options([
                                'cpf'    => 'CPF',
                                'cnpj'   => 'CNPJ',
                                'email'  => 'E-mail',
                                'phone'  => 'Celular',
                                'random' => 'Chave Aleatória',
                            ])
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('application_name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->default(fn ($record) => $record->user?->name ?? '-'),

                Tables\Columns\TextColumn::make('application_email')
                    ->label('Email')
                    ->searchable()
                    ->default(fn ($record) => $record->user?->email ?? '-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('referral_code')
                    ->label('Código')
                    ->copyable()
                    ->copyMessage('Código copiado!')
                    ->searchable(),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Comissão')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('referrals_count')
                    ->label('Referrals')
                    ->counts('referrals')
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversions')
                    ->label('Conversões')
                    ->getStateUsing(fn ($record) => $record->referrals()->where('status', 'converted')->count())
                    ->sortable(false),

                Tables\Columns\TextColumn::make('total_earned')
                    ->label('Total Ganho')
                    ->money('BRL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('saldo')
                    ->label('Saldo')
                    ->money('BRL')
                    ->getStateUsing(fn ($record) => (float) $record->total_earned - (float) $record->total_withdrawn)
                    ->sortable(false),

                Tables\Columns\BadgeColumn::make('approval_status')
                    ->label('Aprovação')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => fn ($state) => in_array($state, ['rejected', 'suspended']),
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'   => 'Pendente',
                        'approved'  => 'Aprovado',
                        'rejected'  => 'Rejeitado',
                        'suspended' => 'Suspenso',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Candidatura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Status de Aprovação')
                    ->options([
                        'pending'   => 'Pendentes',
                        'approved'  => 'Aprovados',
                        'rejected'  => 'Rejeitados',
                        'suspended' => 'Suspensos',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Aprovar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->approval_status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taxa de Comissão (%)')
                            ->numeric()
                            ->default(30)
                            ->required(),
                        Forms\Components\TextInput::make('max_ai_videos_per_month')
                            ->label('Max vídeos IA / mês')
                            ->numeric()
                            ->default(0),
                    ])
                    ->action(function (Affiliate $record, array $data): void {
                        $record->update([
                            'approval_status'         => 'approved',
                            'status'                  => 'active',
                            'approved_at'             => now(),
                            'approved_by'             => auth()->id(),
                            'commission_rate'         => $data['commission_rate'],
                            'max_ai_videos_per_month' => $data['max_ai_videos_per_month'] ?? 0,
                        ]);

                        AffiliateAccessService::grant($record); // SEL-386

                        $link  = rtrim(config('app.url'), '/') . '/api/r/' . $record->referral_code;
                        $email = $record->user?->email ?? $record->application_email;
                        $name  = $record->user?->name  ?? $record->application_name;

                        if ($email) {
                            try {
                                Mail::raw(
                                    "Ola {$name}!\n\nParabens! Sua candidatura ao programa de afiliados do Seller Global foi aprovada.\n\nSeu link exclusivo:\n{$link}\n\nComissao: {$data['commission_rate']}% na primeira fatura de cada cliente indicado.\n\nEquipe Seller Global",
                                    fn ($m) => $m->to($email)->subject('Voce foi aprovado no Programa de Afiliados!')
                                );
                            } catch (\Throwable $e) {}
                        }

                        Notification::make()
                            ->title("Afiliado {$name} aprovado!")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->approval_status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo da rejeição')
                            ->required(),
                    ])
                    ->action(function (Affiliate $record, array $data): void {
                        $record->update([
                            'approval_status'  => 'rejected',
                            'status'           => 'inactive',
                            'rejection_reason' => $data['reason'],
                        ]);
                        Notification::make()->title('Afiliado rejeitado')->warning()->send();
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label('Suspender')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn ($record) => $record->approval_status === 'approved')
                    ->requiresConfirmation()
                    ->action(function (Affiliate $record): void {
                        $record->update(['approval_status' => 'suspended', 'status' => 'suspended']);
                        AffiliateAccessService::revoke($record); // SEL-386
                        Notification::make()->title('Afiliado suspenso')->warning()->send();
                    }),

                Tables\Actions\Action::make('reactivate')
                    ->label('Reativar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record) => $record->approval_status === 'suspended')
                    ->requiresConfirmation()
                    ->action(function (Affiliate $record): void {
                        $record->update(['approval_status' => 'approved', 'status' => 'active']);
                        Notification::make()->title('Afiliado reativado')->success()->send();
                    }),

                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\ViewAction::make()->label('Ver'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_approve')
                        ->label('Aprovar selecionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        // SEL-385: aprovava sem liberar videos e sem avisar o afiliado (3 afiliados ficaram
                        // aprovados, zerados e sem receber o link). Agora espelha a acao individual.
                        ->form([
                            Forms\Components\TextInput::make('commission_rate')
                                ->label('Comissao (%)')->numeric()->default(30)->required(),
                            Forms\Components\TextInput::make('max_ai_videos_per_month')
                                ->label('Max videos IA / mes')->numeric()->default(10)->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $enviados = 0;
                            $records->each(function (Affiliate $record) use ($data, &$enviados): void {
                                if ($record->approval_status !== 'pending') {
                                    return;
                                }
                                $record->update([
                                    'approval_status'         => 'approved',
                                    'status'                  => 'active',
                                    'approved_at'             => now(),
                                    'approved_by'             => auth()->id(),
                                    'commission_rate'         => $data['commission_rate'],
                                    'max_ai_videos_per_month' => $data['max_ai_videos_per_month'] ?? 0,
                                ]);

                                AffiliateAccessService::grant($record); // SEL-386

                                $link  = rtrim(config('app.url'), '/') . '/api/r/' . $record->referral_code;
                                $email = $record->user?->email ?? $record->application_email;
                                $name  = $record->user?->name  ?? $record->application_name;

                                if ($email) {
                                    try {
                                        Mail::raw(
                                            "Ola {$name}!\n\nParabens! Sua candidatura ao programa de afiliados do Seller Global foi aprovada.\n\nSeu link exclusivo:\n{$link}\n\nComissao: {$data['commission_rate']}% na primeira fatura de cada cliente indicado.\n\nEquipe Seller Global",
                                            fn ($m) => $m->to($email)->subject('Voce foi aprovado no Programa de Afiliados!')
                                        );
                                        $enviados++;
                                    } catch (\Throwable $e) {}
                                }
                            });
                            Notification::make()->title("Afiliados aprovados! Avisos enviados: {$enviados}")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('bulk_update_commission')
                        ->label('Alterar comissão em massa')
                        ->icon('heroicon-o-percent-badge')
                        ->form([
                            Forms\Components\TextInput::make('commission_rate')
                                ->label('Nova taxa (%)')
                                ->numeric()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn ($r) => $r->update(['commission_rate' => $data['commission_rate']]));
                            Notification::make()->title('Comissão atualizada!')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AffiliateResource\RelationManagers\ReferralsRelationManager::class,
            AffiliateResource\RelationManagers\CommissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliates::route('/'),
            'create' => Pages\CreateAffiliate::route('/create'),
            'edit'   => Pages\EditAffiliate::route('/{record}/edit'),
            'view'   => Pages\ViewAffiliate::route('/{record}'),
        ];
    }
}
