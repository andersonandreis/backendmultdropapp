<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class AcoesProdutos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?string $navigationLabel = 'Ações em Produtos';
    protected static ?string $title = 'Ações em Massa — Produtos';
    protected static ?string $slug = 'acoes-produtos';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.acoes-produtos';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('acao')
                    ->label('Ação')
                    ->options([
                        'criar'     => 'Criar Produtos',
                        'atualizar' => 'Atualizar Produtos',
                        'preco'     => 'Atualizar Preços',
                        'estoque'   => 'Atualizar Estoque',
                        'remover'   => 'Remover Produtos',
                    ])
                    ->required()
                    ->native(false),

                FileUpload::make('planilha')
                    ->label('Planilha (.xlsx / .csv)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'application/csv',
                    ])
                    ->directory('imports/produtos')
                    ->required(),
            ])
            ->statePath('data');
    }

    public function upload(): void
    {
        $this->form->validate();

        $acao = $this->data['acao'] ?? 'N/A';
        $file = $this->data['planilha'] ?? null;

        if (!$file) {
            Notification::make()->title('Nenhum arquivo enviado')->danger()->send();
            return;
        }

        // Aqui seria disparado um Job de processamento da planilha
        // \App\Jobs\ImportProductsSpreadsheet::dispatch($file, $acao, auth()->id());

        Notification::make()
            ->title('Planilha recebida!')
            ->body("Ação: {$acao} — O processamento foi agendado e você receberá uma notificação ao finalizar.")
            ->success()
            ->send();

        $this->form->fill();
    }

    public function downloadModelo(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = ['SKU', 'Nome', 'Preco', 'Estoque', 'Categoria'];
        $rows = [
            ['SKU-001', 'Produto Exemplo', '99.90', '100', 'Eletronicos'],
        ];

        return response()->streamDownload(function () use ($headers, $rows) {
            $f = fopen('php://output', 'w');
            fputcsv($f, $headers);
            foreach ($rows as $row) {
                fputcsv($f, $row);
            }
            fclose($f);
        }, 'modelo-importacao-produtos.csv', ['Content-Type' => 'text/csv']);
    }

    public function downloadTodos(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $produtos = \App\Models\Product::select('sku', 'name', 'price', 'stock_quantity', 'category_id')->get();

        return response()->streamDownload(function () use ($produtos) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['SKU', 'Nome', 'Preco', 'Estoque', 'Categoria']);
            foreach ($produtos as $p) {
                fputcsv($f, [$p->sku, $p->name, $p->price, $p->stock_quantity, $p->category_id]);
            }
            fclose($f);
        }, 'todos-produtos.csv', ['Content-Type' => 'text/csv']);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('download_modelo')
                ->label('Baixar Modelo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action('downloadModelo'),

            \Filament\Actions\Action::make('download_todos')
                ->label('Baixar Todos')
                ->icon('heroicon-o-table-cells')
                ->color('info')
                ->action('downloadTodos'),
        ];
    }
}
