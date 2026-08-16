<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class RemetentesDeposito extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Remetentes';
    protected static ?string $title = 'Remetentes do Deposito';
    protected static ?string $slug = 'remetentes-deposito';
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.remetentes-deposito';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Supplier::query()
                    ->with(['user'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('company_name')
                    ->label('Empresa')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('Responsável')
                    ->searchable(),

                TextColumn::make('user.email')
                    ->label('E-mail')
                    ->copyable(),

                TextColumn::make('document')
                    ->label('CNPJ/CPF'),

                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->default(true),

                TextColumn::make('created_at')
                    ->label('Desde')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->searchable()
            ->defaultSort('created_at', 'desc');
    }
}
