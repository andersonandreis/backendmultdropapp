<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierUserResource\Pages;
use App\Jobs\InviteSupplierUserJob;
use App\Models\SupplierUser;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** NOV-131 — Gerenciamento de colaboradores do supplier (multi-user). */
class SupplierUserResource extends Resource
{
    protected static ?string $model = SupplierUser::class;
    protected static ?string $slug = 'equipe';
    protected static ?string $modelLabel = 'Colaborador';
    protected static ?string $pluralModelLabel = 'Minha Equipe';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Equipe & Acessos';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Convite / Vinculação')->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')->required()
                    ->visible(fn () => auth()->user()?->role === 'super_admin'),
                Forms\Components\Select::make('user_id')
                    ->label('Usuário (já cadastrado)')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('role')->options([
                    'admin'      => 'Admin (acesso total)',
                    'operador'   => 'Operador',
                    'estoque'    => 'Estoque',
                    'financeiro' => 'Financeiro',
                    'sac'        => 'SAC',
                    'logistica'  => 'Logística',
                ])->required()->default('operador'),
                Forms\Components\Toggle::make('active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Permissões customizadas (opcional)')->schema([
                Forms\Components\CheckboxList::make('permissions')->label('Permissões')->options([
                    'orders.view'        => 'Ver pedidos',
                    'orders.update'      => 'Atualizar pedidos',
                    'orders.pay'         => 'Pagar pedidos',
                    'products.view'      => 'Ver produtos',
                    'products.manage'    => 'Gerenciar produtos',
                    'inventory.manage'   => 'Gerenciar estoque',
                    'shipments.manage'   => 'Gerenciar remessas',
                    'tracking.update'    => 'Atualizar rastreio',
                    'billing.view'       => 'Ver faturamento',
                    'reports.view'       => 'Ver relatórios',
                    'support.manage'     => 'Gerenciar SAC',
                    'nfe.manage'         => 'Gerenciar NF-e',
                    'questions.answer'   => 'Responder perguntas',
                ])->columns(3)
                ->helperText('Deixe vazio para usar permissões padrão do role.'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(SupplierUser::query()->with(['user', 'supplier']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('user.email')->label('Email')->searchable(),
                Tables\Columns\BadgeColumn::make('role')->colors([
                    'danger'  => 'admin',
                    'info'    => ['operador', 'sac'],
                    'warning' => ['estoque', 'logistica'],
                    'success' => 'financeiro',
                ]),
                Tables\Columns\IconColumn::make('active')->boolean(),
                Tables\Columns\TextColumn::make('accepted_at')->label('Aceito em')->dateTime('d/m/Y')->placeholder('Pendente'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reenviar_convite')
                    ->label('Reenviar convite')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (SupplierUser $r) => !$r->accepted_at)
                    ->action(function (SupplierUser $r) {
                        InviteSupplierUserJob::dispatch($r->id, $r->user?->email ?? '');
                        Notification::make()->title('Convite reenviado')->success()->send();
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('convidar')
                    ->label('Convidar por email')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('email')->email()->required(),
                        Forms\Components\Select::make('role')->options([
                            'admin' => 'Admin', 'operador' => 'Operador',
                            'estoque' => 'Estoque', 'financeiro' => 'Financeiro',
                            'sac' => 'SAC', 'logistica' => 'Logística',
                        ])->required()->default('operador'),
                    ])
                    ->action(function (array $data) {
                        $supplierId = auth()->user()->supplier?->id;
                        if (!$supplierId) {
                            Notification::make()->title('Sem supplier vinculado')->danger()->send();
                            return;
                        }
                        $user = User::firstOrCreate(
                            ['email' => $data['email']],
                            ['name' => explode('@', $data['email'])[0], 'password' => bcrypt(Str::random(20)), 'role' => 'supplier']
                        );
                        $su = SupplierUser::firstOrCreate([
                            'supplier_id' => $supplierId,
                            'user_id'     => $user->id,
                        ], [
                            'role'               => $data['role'],
                            'active'             => true,
                            'invite_token'       => Str::random(64),
                            'invited_at'         => now(),
                            'invited_by_user_id' => auth()->id(),
                        ]);
                        InviteSupplierUserJob::dispatch($su->id, $data['email']);
                        Notification::make()->title('Convite enviado para '.$data['email'])->success()->send();
                    }),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierUsers::route('/'),
            'create' => Pages\CreateSupplierUser::route('/create'),
            'edit'   => Pages\EditSupplierUser::route('/{record}/edit'),
        ];
    }
}
