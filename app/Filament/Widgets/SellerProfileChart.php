<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\User;

class SellerProfileChart extends ChartWidget
{
    protected static ?string $heading = 'Análise Dedo-Duro: CPF vs CNPJ (Conversão)';
    protected static ?int $sort = 5; // Movendo pro final do layout do admin

    public static function canView(): bool
    {
        return auth()->user()->profile === 'admin';
    }

    protected function getData(): array
    {
        // Mocking Data para apresentação do "Absurdo" 
        // Lógica Real: User::where('document_type', 'cpf')->has('orders')->count();
        $cpfSellersActive = 45;
        $cpfSellersInactive = 150;

        $cnpjSellersActive = 89;
        $cnpjSellersInactive = 20;

        return [
            'datasets' => [
                [
                    'label' => 'Vendedores Ativos (Com Vendas)',
                    'data' => [$cpfSellersActive, $cnpjSellersActive],
                    'backgroundColor' => '#10b981', // Emerald
                ],
                [
                    'label' => 'Vendedores Inativos (Zero Vendas)',
                    'data' => [$cpfSellersInactive, $cnpjSellersInactive],
                    'backgroundColor' => '#ef4444', // Red
                ],
            ],
            'labels' => ['Cadastros CPF', 'Cadastros CNPJ'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
