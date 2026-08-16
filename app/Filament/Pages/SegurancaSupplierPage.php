<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SegurancaSupplierPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Segurança';
    protected static ?string $title = 'Segurança do Fornecedor';
    protected static ?string $slug = 'seguranca';
    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.seguranca-supplier';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user?->role === 'super_admin'
            || ($user?->role === 'supplier' && $user->supplier);
    }

    public function mount(): void
    {
        $supplier = auth()->user()?->supplier;
        $this->form->fill([
            'two_factor_required' => (bool) ($supplier?->two_factor_required ?? false),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Verificação em 2 etapas (2FA)')
                    ->description('Quando ativado, todos os colaboradores do fornecedor precisarão configurar 2FA no próximo login.')
                    ->schema([
                        Toggle::make('two_factor_required')
                            ->label('Exigir verificação em 2 etapas dos colaboradores')
                            ->helperText('Recomendado para fornecedores que manipulam dados sensíveis ou volume alto de operações.')
                            ->live(),
                    ]),
            ]);
    }

    public function salvar(): void
    {
        $supplier = auth()->user()?->supplier;

        if (!$supplier) {
            Notification::make()
                ->title('Sem fornecedor vinculado')
                ->body('Seu usuário não possui um fornecedor para configurar segurança.')
                ->danger()
                ->send();
            return;
        }

        $supplier->update([
            'two_factor_required' => (bool) ($this->data['two_factor_required'] ?? false),
        ]);

        Notification::make()
            ->title('Configurações de segurança salvas')
            ->success()
            ->send();
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
