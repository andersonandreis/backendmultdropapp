<?php

namespace App\Filament\App\Resources\OrderResource\Pages;

use App\Filament\App\Resources\OrderResource;
use App\Models\OrderLabelQueue;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class LabelQueueDashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = OrderResource::class;
    protected static ?string $title = 'Fila de Etiquetas (Marketplace)';
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static string $view = 'filament.app.resources.order-resource.pages.label-queue-dashboard';

    public function table(Table $table): Table
    {
        return $table
            ->query(OrderLabelQueue::query()->with('order'))
            ->columns([
                TextColumn::make('order.order_number')->label('Pedido')->searchable(),
                TextColumn::make('order.source')->label('Canal'),
                TextColumn::make('status')->badge()->color(fn(string $state): string => match ($state) {
                    'pending' => 'warning',
                    'checking' => 'info',
                    'available' => 'success',
                    'failed' => 'danger',
                })->label('Status da Fila'),
                TextColumn::make('attempts')->label('Tentativas'),
                TextColumn::make('next_check_at')->label('Próxima Checagem')->dateTime(),
                TextColumn::make('error_log')->label('Último Retorno API')->limit(50),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
