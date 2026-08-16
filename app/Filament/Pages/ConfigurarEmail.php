<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

class ConfigurarEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Email';
    protected static ?string $title = 'Configuração de Email (SMTP)';
    protected static ?string $slug = 'configurar-email';
    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.configurar-email';

    public ?array $data = [];

    private function getSetting(string $key, string $default = ''): string
    {
        return Setting::where('key', $key)->value('value') ?? $default;
    }

    private function setSetting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'email']);
    }

    public function mount(): void
    {
        $this->form->fill([
            'mail_host'       => $this->getSetting('mail_host', 'smtp.gmail.com'),
            'mail_port'       => $this->getSetting('mail_port', '587'),
            'mail_username'   => $this->getSetting('mail_username'),
            'mail_password'   => $this->getSetting('mail_password'),
            'mail_encryption' => $this->getSetting('mail_encryption', 'tls'),
            'mail_from_name'  => $this->getSetting('mail_from_name', 'HubAI'),
            'mail_from_address' => $this->getSetting('mail_from_address'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configuração SMTP')
                    ->columns(2)
                    ->schema([
                        TextInput::make('mail_host')
                            ->label('Host SMTP')
                            ->required(),

                        TextInput::make('mail_port')
                            ->label('Porta')
                            ->numeric()
                            ->required(),

                        TextInput::make('mail_username')
                            ->label('Usuário / E-mail')
                            ->email()
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('mail_password')
                            ->label('Senha / App Password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('mail_encryption')
                            ->label('Criptografia')
                            ->helperText('tls ou ssl'),

                        TextInput::make('mail_from_name')
                            ->label('Nome do Remetente'),

                        TextInput::make('mail_from_address')
                            ->label('E-mail do Remetente')
                            ->email()
                            ->columnSpan(2),
                    ]),
            ])
            ->statePath('data');
    }

    public function salvar(): void
    {
        $this->form->validate();

        $campos = ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_name', 'mail_from_address'];
        foreach ($campos as $campo) {
            $this->setSetting($campo, $this->data[$campo] ?? null);
        }

        Notification::make()->title('Configurações de e-mail salvas!')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('salvar')
                ->label('Salvar')
                ->submit('salvar'),
        ];
    }
}
