<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

class GerenciarColaboradores extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Clientes & Sellers';
    protected static ?string $navigationLabel = 'Gerenciar Colaboradores';
    protected static ?string $title = 'Colaboradores da Equipe';
    protected static ?string $slug = 'gerenciar-colaboradores';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.gerenciar-colaboradores';

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('novo_colaborador')
                ->label('Novo Colaborador')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->required()
                        ->unique(User::class, 'email'),

                    TextInput::make('password')
                        ->label('Senha')
                        ->password()
                        ->required()
                        ->minLength(8),

                    Select::make('departamento')
                        ->label('Departamento')
                        ->options([
                            'logistica'  => 'Logística',
                            'financeiro' => 'Financeiro',
                            'suporte'    => 'Suporte',
                            'comercial'  => 'Comercial',
                            'ti'         => 'TI',
                        ])
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    User::create([
                        'name'              => $data['name'],
                        'email'             => $data['email'],
                        'password'          => Hash::make($data['password']),
                        'role'              => 'supplier',
                        'email_verified_at' => now(),
                        'is_active'         => true,
                    ]);

                    Notification::make()->title('Colaborador criado!')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->where('role', 'supplier')
                    ->latest()
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Perfil')
                    ->badge()
                    ->color('info'),

                TextColumn::make('created_at')
                    ->label('Desde')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                Action::make('desativar')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->update(['email_verified_at' => null]);
                        Notification::make()->title('Colaborador desativado.')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
