<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierSmtpConfigResource\Pages;
use App\Models\SupplierSmtpConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

class SupplierSmtpConfigResource extends Resource
{
    protected static ?string $model = SupplierSmtpConfig::class;
    protected static ?string $slug = 'smtp-configs';

    protected static ?string $navigationIcon = 'heroicon-o-at-symbol';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Configurar Email (SMTP)';
    protected static ?string $modelLabel = 'Configuração SMTP';
    protected static ?string $pluralModelLabel = 'Configurações SMTP';
    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $query->where('supplier_id', $user->supplier->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Conexão SMTP')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('smtp_host')
                        ->label('Host SMTP')
                        ->required()
                        ->placeholder('smtp.gmail.com'),

                    Forms\Components\TextInput::make('smtp_port')
                        ->label('Porta')
                        ->numeric()
                        ->required()
                        ->default(587),

                    Forms\Components\TextInput::make('smtp_user')
                        ->label('Usuário / E-mail')
                        ->email()
                        ->required()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('smtp_password')
                        ->label('Senha / App Password')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Deixe em branco para manter a senha atual.')
                        ->columnSpan(2),

                    Forms\Components\Select::make('smtp_encryption')
                        ->label('Criptografia')
                        ->options([
                            'tls'  => 'TLS',
                            'ssl'  => 'SSL',
                            'none' => 'Nenhum',
                        ])
                        ->default('tls')
                        ->required(),

                    Forms\Components\Toggle::make('active')
                        ->label('Ativo')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Identidade do Remetente')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('smtp_from_name')
                        ->label('Nome do Remetente')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('smtp_from_email')
                        ->label('E-mail do Remetente')
                        ->email()
                        ->helperText('Se em branco, usa o usuário SMTP'),
                ]),

            Forms\Components\Hidden::make('supplier_id')
                ->default(fn () => auth()->user()?->supplier?->id),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('smtp_host')
                    ->label('Host')
                    ->searchable(),

                Tables\Columns\TextColumn::make('smtp_port')
                    ->label('Porta'),

                Tables\Columns\TextColumn::make('smtp_user')
                    ->label('Usuário')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('smtp_encryption')
                    ->label('Cripto')
                    ->colors([
                        'success' => 'tls',
                        'info'    => 'ssl',
                        'gray'    => 'none',
                    ]),

                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\IconColumn::make('last_test_success')
                    ->label('Último teste')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('last_test_at')
                    ->label('Testado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca'),
            ])
            ->actions([
                Tables\Actions\Action::make('testar')
                    ->label('Testar Conexão')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->form([
                        Forms\Components\TextInput::make('to')
                            ->label('Enviar e-mail de teste para')
                            ->email()
                            ->required()
                            ->default(fn () => auth()->user()?->email),
                    ])
                    ->action(function (SupplierSmtpConfig $record, array $data): void {
                        try {
                            config([
                                'mail.mailers.supplier_test.transport'  => 'smtp',
                                'mail.mailers.supplier_test.host'       => $record->smtp_host,
                                'mail.mailers.supplier_test.port'       => $record->smtp_port,
                                'mail.mailers.supplier_test.username'   => $record->smtp_user,
                                'mail.mailers.supplier_test.password'   => $record->smtp_password,
                                'mail.mailers.supplier_test.encryption' => $record->smtp_encryption === 'none' ? null : $record->smtp_encryption,
                                'mail.mailers.supplier_test.timeout'    => 15,
                            ]);

                            Mail::mailer('supplier_test')->raw(
                                "Teste de conexão SMTP do fornecedor #{$record->supplier_id} - " . now()->format('d/m/Y H:i:s'),
                                function ($m) use ($data, $record) {
                                    $m->to($data['to'])
                                      ->from($record->smtp_from_email ?: $record->smtp_user, $record->smtp_from_name)
                                      ->subject('Teste SMTP - HubAI');
                                }
                            );

                            $record->update([
                                'last_test_at'      => now(),
                                'last_test_success' => true,
                                'last_test_error'   => null,
                            ]);

                            Notification::make()
                                ->title('Conexão OK')
                                ->body("E-mail de teste enviado para {$data['to']}.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->update([
                                'last_test_at'      => now(),
                                'last_test_success' => false,
                                'last_test_error'   => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Falha na conexão SMTP')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierSmtpConfigs::route('/'),
            'create' => Pages\CreateSupplierSmtpConfig::route('/create'),
            'edit'   => Pages\EditSupplierSmtpConfig::route('/{record}/edit'),
        ];
    }
}
