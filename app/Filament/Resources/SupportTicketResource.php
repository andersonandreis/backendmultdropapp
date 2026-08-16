<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** NOV-130 — Painel de tickets SAC supplier-scoped. */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;
    protected static ?string $slug = 'sac-tickets';
    protected static ?string $modelLabel = 'Ticket';
    protected static ?string $pluralModelLabel = 'SAC — Tickets';
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        }
        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('status', ['open', 'in_progress'])->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Classificação')->schema([
                Forms\Components\Select::make('department_id')
                    ->label('Setor')
                    ->relationship('department', 'name')
                    ->searchable(),
                Forms\Components\Select::make('topic_id')
                    ->label('Tópico')
                    ->relationship('topic', 'name')
                    ->searchable(),
                Forms\Components\Select::make('priority')->options([
                    'low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente',
                ])->default('normal'),
                Forms\Components\Select::make('status')->options([
                    'open' => 'Aberto', 'in_progress' => 'Em atendimento',
                    'resolved' => 'Resolvido', 'closed' => 'Fechado',
                ])->required(),
                Forms\Components\Select::make('operator_user_id')
                    ->label('Operador')
                    ->relationship('operator', 'name')
                    ->searchable(),
            ])->columns(2),
            Forms\Components\Section::make('Conteúdo')->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(200),
                Forms\Components\Textarea::make('description')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(SupportTicket::query()->with(['client', 'department', 'topic', 'operator']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Aberto')->dateTime('d/m H:i')->sortable(),
                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                Tables\Columns\TextColumn::make('client.company_name')->label('Cliente')->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('title')->label('Assunto')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('department.name')->label('Setor')->placeholder('—'),
                Tables\Columns\TextColumn::make('topic.name')->label('Tópico')->placeholder('—'),
                Tables\Columns\BadgeColumn::make('priority')->colors([
                    'gray' => 'low', 'info' => 'normal', 'warning' => 'high', 'danger' => 'urgent',
                ]),
                Tables\Columns\BadgeColumn::make('status')->colors([
                    'warning' => 'open', 'info' => 'in_progress',
                    'success' => 'resolved', 'gray' => 'closed',
                ]),
                Tables\Columns\TextColumn::make('operator.name')->label('Operador')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Aberto', 'in_progress' => 'Em atendimento',
                    'resolved' => 'Resolvido', 'closed' => 'Fechado',
                ])->default('open'),
                SelectFilter::make('priority')->options([
                    'low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente',
                ]),
                SelectFilter::make('department_id')->relationship('department', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Abrir'),
                Tables\Actions\Action::make('assumir')
                    ->label('Assumir')
                    ->icon('heroicon-o-hand-raised')
                    ->color('primary')
                    ->visible(fn (SupportTicket $r) => $r->status === 'open' && !$r->operator_user_id)
                    ->action(function (SupportTicket $r) {
                        $r->operator_user_id = auth()->id();
                        $r->status = 'in_progress';
                        $r->first_response_at = $r->first_response_at ?? now();
                        $r->save();
                        Notification::make()->title('Ticket assumido')->success()->send();
                    }),
                Tables\Actions\Action::make('responder')
                    ->label('Responder')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->form([
                        Forms\Components\Textarea::make('body')->label('Mensagem')->required()->rows(4),
                    ])
                    ->action(function (SupportTicket $r, array $data) {
                        SupportTicketMessage::create([
                            'ticket_id'        => $r->id,
                            'author_type'      => 'agent',
                            'author_user_id'   => auth()->id(),
                            'body'             => $data['body'],
                        ]);
                        if ($r->status === 'open') {
                            $r->status = 'in_progress';
                            $r->first_response_at = $r->first_response_at ?? now();
                            $r->save();
                        }
                        Notification::make()->title('Resposta enviada')->success()->send();
                    }),
                Tables\Actions\Action::make('resolver')
                    ->label('Resolver')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SupportTicket $r) => !in_array($r->status, ['resolved', 'closed']))
                    ->action(function (SupportTicket $r) {
                        $r->status = 'resolved';
                        $r->resolved_at = now();
                        $r->save();
                        Notification::make()->title('Ticket resolvido')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('fecharLote')
                        ->label('Fechar selecionados')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each(function ($r) {
                            $r->status = 'closed';
                            $r->closed_at = now();
                            $r->closed_by_user_id = auth()->id();
                            $r->save();
                        })),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportTickets::route('/'),
            'create' => Pages\CreateSupportTicket::route('/create'),
            'edit'   => Pages\EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
