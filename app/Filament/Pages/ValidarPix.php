<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ValidarPix extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $navigationLabel = 'Validar Pix';
    protected static ?string $title = 'Validar Conta Pix';
    protected static ?string $slug = 'validar-pix';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.validar-pix';

    public ?array $data = [];
    public ?array $resultado = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('tipo_chave')
                    ->label('Tipo de Chave')
                    ->options([
                        'cpf'    => 'CPF',
                        'cnpj'   => 'CNPJ',
                        'email'  => 'E-mail',
                        'phone'  => 'Celular',
                        'random' => 'Chave Aleatória',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('chave_pix')
                    ->label('Chave Pix')
                    ->placeholder('Informe a chave para validar...')
                    ->required(),
            ])
            ->statePath('data');
    }

    public function validar(): void
    {
        $this->form->validate();

        $tipo  = $this->data['tipo_chave'] ?? '';
        $chave = trim($this->data['chave_pix'] ?? '');

        // Validacao local basica
        $valido = match ($tipo) {
            'cpf'    => preg_match('/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/', $chave),
            'cnpj'   => preg_match('/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/', $chave),
            'email'  => filter_var($chave, FILTER_VALIDATE_EMAIL),
            'phone'  => preg_match('/^\+?55?\d{10,11}$/', preg_replace('/\D/', '', $chave)),
            'random' => strlen($chave) >= 32,
            default  => false,
        };

        if ($valido) {
            $this->resultado = [
                'status'  => 'valido',
                'tipo'    => $tipo,
                'chave'   => $chave,
                'message' => 'Formato de chave Pix válido.',
            ];
            Notification::make()->title('Chave Pix válida')->success()->send();
        } else {
            $this->resultado = [
                'status'  => 'invalido',
                'tipo'    => $tipo,
                'chave'   => $chave,
                'message' => 'Formato de chave Pix inválido para o tipo selecionado.',
            ];
            Notification::make()->title('Chave Pix inválida')->danger()->send();
        }
    }
}
