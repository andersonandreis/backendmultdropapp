<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $slug = 'assinantes';
    protected static ?string $modelLabel = 'Lojista (Seller)';
    protected static ?string $pluralModelLabel = 'Lojistas (Sellers)';

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Clientes & Sellers';
    protected static ?string $navigationLabel = 'Lojistas (Sellers)';
    protected static ?int $navigationSort = 1;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Usuário Vinculado')
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome completo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email')
                            ->validationMessages(['unique' => 'Este e-mail já está cadastrado no sistema.'])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->required()
                            ->minLength(8)
                            ->helperText('Mínimo 8 caracteres.')
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $user = User::create([
                            'name'               => $data['name'],
                            'email'              => $data['email'],
                            'password'           => $data['password'], // hashed pelo cast 'hashed' do Model User
                            'role'               => 'client',
                            'email_verified_at'  => now(),
                        ]);
                        return $user->id;
                    })
                    ->helperText('Selecione um usuário existente ou clique em "Criar" para criar um novo.'),
                // supplier_id removido: coluna dropada na migration 2026_03_08_195801
                // MUL-269 fase 2: company_name deixou de existir em clients — nome vem
                // do user conectado. Aqui mostramos so leitura pra referencia.
                Forms\Components\Placeholder::make('company_name_display')
                    ->label('Nome do Seller (do usuario conectado)')
                    ->content(fn ($record) => $record?->company_name ?? '—'),
                Forms\Components\TextInput::make('document')
                    ->label('CNPJ / CPF')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('listing_mode')
                    ->label('Modo de Sincronização')
                    ->options([
                        'manual'    => 'Manual',
                        'semi_auto' => 'Semi-Automático',
                        'full_auto' => 'Automático',
                    ])
                    ->required()
                    ->default('manual'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Conta Ativa')
                    ->required()
                    ->default(true),
                \Filament\Forms\Components\Section::make('Plano e Assinatura')
                    ->description(function ($record) {
                        if (!$record) return null;
                        $sub = $record->subscriptions()
                            ->whereIn('status', ['active', 'trialing'])
                            ->with('plan')
                            ->latest()
                            ->first();
                        return $sub
                            ? '✅ Plano ativo: ' . ($sub->plan?->name ?? '—') . ' | Status: ' . $sub->status
                            : '⚠️ Nenhum plano ativo. Acesse o usuário vinculado para atribuir um plano.';
                    })
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('subscription_note')
                            ->label('')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<p class="text-sm text-gray-500">Planos são gerenciados pelo UserResource. Acesse o usuário vinculado a este lojista para alterar o plano.</p>'
                            )),
                    ])
                    ->collapsible()
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // MUL-269 fase 2: company_name via accessor (user->full_name || user->name).
                // Sortable removido: nao ha coluna fisica. Busca via user.
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Loja / Razão Social')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable(),
                // supplier.company_name removido: relacionamento não existe (supplier_id foi dropado)

                Tables\Columns\TextColumn::make('plano_ativo')
                    ->label('Plano')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $sub = $record->subscriptions()
                            ->whereIn('status', ['active', 'trialing'])
                            ->with('plan')
                            ->latest()
                            ->first();
                        return $sub?->plan?->name ?? 'Sem plano';
                    })
                    ->color(fn($state) => $state === 'Sem plano' ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('document')
                    ->label('Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('listing_mode')
                    ->label('Modo Sync')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'semi_auto' => 'Semi-Auto',
                        'full_auto' => 'Automático',
                        default     => 'Manual',
                    })
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\Action::make('atribuir_plano')
                    ->label('Atribuir Plano')
                    ->icon('heroicon-o-credit-card')
                    ->color('warning')
                    ->visible(function ($record) {
                        $sub = $record->subscriptions()
                            ->whereIn('status', ['active', 'trialing'])
                            ->first();
                        return !$sub;
                    })
                    ->url(fn($record) => route('filament.admin.resources.assinantes.edit', $record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SuppliersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit'   => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
