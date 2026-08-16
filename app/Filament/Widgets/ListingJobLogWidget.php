<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ListingJobLogWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Log de Jobs (ultimos 50)';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('80px'),

                Tables\Columns\TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(20),

                Tables\Columns\TextColumn::make('product_name')
                    ->label('Produto')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->product_name ?? ''),

                Tables\Columns\TextColumn::make('marketplace')
                    ->label('Marketplace')
                    ->badge()
                    ->color(fn ($state) => match (strtolower($state ?? '')) {
                        'mercadolivre', 'ml' => 'warning',
                        'shopee'             => 'danger',
                        default              => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'pending'    => 'warning',
                        'processing' => 'info',
                        'done'       => 'success',
                        'failed'     => 'danger',
                        'skipped'    => 'gray',
                        default      => 'gray',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Tentativas')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->error_message ?? '')
                    ->color('danger')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100])
            ->poll('10s');
    }

    private function buildQuery(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable('product_listing_jobs')) {
            return \App\Models\User::query()->whereRaw('1 = 0');
        }

        return DB::table('product_listing_jobs')
            ->select([
                'product_listing_jobs.id',
                'product_listing_jobs.client_id',
                DB::raw('COALESCE(u.name, u.email, CONCAT("Cliente #", product_listing_jobs.client_id)) as client_name'),
                'product_listing_jobs.product_name',
                'product_listing_jobs.marketplace',
                'product_listing_jobs.status',
                'product_listing_jobs.attempts',
                'product_listing_jobs.error_message',
                'product_listing_jobs.updated_at',
            ])
            ->leftJoin('clients', 'clients.id', '=', 'product_listing_jobs.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'clients.user_id')
            ->orderByDesc('product_listing_jobs.updated_at')
            ->limit(50);
    }
}
