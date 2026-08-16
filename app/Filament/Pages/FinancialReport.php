<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\SupplierTransaction;
use App\Models\Order;
use App\Models\WithdrawalRequest;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class FinancialReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';
    protected static ?string $navigationGroup = 'Análises';
    protected static ?string $navigationLabel = 'Relatorio Financeiro';
    protected static ?string $title = 'Platform Financial Overview';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.financial-report';

    public function table(Table $table): Table
    {
        // View-only metrics of SupplierTransactions
        return $table
            ->query(SupplierTransaction::query()->latest())
            ->columns([
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable(),
                TextColumn::make('type')->badge()->color(fn(string $state) => match ($state) {
                    'credit' => 'success',
                    'debit' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('amount')->money('BRL')->sortable(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
