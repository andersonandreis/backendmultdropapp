<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ListingQueueTableWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Status por Cliente';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    // SEL-076 fix: Filament 3 nao tem Table::recordKey. Sobrescrevemos getTableRecordKey
    // pra usar client_id como chave estavel do record (a query agrupa por client_id, sem id).
    public function getTableRecordKey($record): string
    {
        return (string) ($record->client_id ?? spl_object_hash($record));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable()
                    ->description(fn ($record) => $record->client_email ?? ''),

                Tables\Columns\TextColumn::make('total_pending')
                    ->label('Pendentes')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('total_processing')
                    ->label('Processando')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('total_done_today')
                    ->label('Concluidos Hoje')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('total_failed')
                    ->label('Falhas')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('dominant_speed')
                    ->label('Velocidade')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fast'   => 'success',
                        'normal' => 'info',
                        'slow'   => 'warning',
                        default  => 'gray',
                    }),
            ])
            ->actions([
                Action::make('pausar')
                    ->label('Pausar')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->action(function ($record): void {
                        try {
                            DB::table('product_listing_jobs')
                                ->where('client_id', $record->client_id)
                                ->where('status', 'pending')
                                ->update(['status' => 'skipped', 'updated_at' => now()]);

                            Notification::make()
                                ->title('Fila pausada')
                                ->body('Jobs pendentes do cliente pausados.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao pausar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('retomar')
                    ->label('Retomar')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(function ($record): void {
                        try {
                            DB::table('product_listing_jobs')
                                ->where('client_id', $record->client_id)
                                ->where('status', 'skipped')
                                ->update(['status' => 'pending', 'updated_at' => now()]);

                            Notification::make()
                                ->title('Fila retomada')
                                ->body('Jobs voltaram para a fila.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao retomar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('limpar_falhas')
                    ->label('Limpar Falhas')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Limpar falhas do cliente?')
                    ->modalDescription('Todos os jobs com status failed deste cliente serao removidos.')
                    ->action(function ($record): void {
                        try {
                            $deleted = DB::table('product_listing_jobs')
                                ->where('client_id', $record->client_id)
                                ->where('status', 'failed')
                                ->delete();

                            Notification::make()
                                ->title('Falhas limpas')
                                ->body("{$deleted} job(s) removidos.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao limpar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        if (! Schema::hasTable('product_listing_jobs')) {
            return \App\Models\User::query()->whereRaw('1 = 0');
        }

        return \App\Models\ProductListingJob::query()
            ->select([
                'product_listing_jobs.client_id',
                DB::raw('COALESCE(u.name, u.email, CONCAT("Cliente #", product_listing_jobs.client_id)) as client_name'),
                DB::raw('u.email as client_email'),
                DB::raw('SUM(CASE WHEN product_listing_jobs.status = "pending" THEN 1 ELSE 0 END) as total_pending'),
                DB::raw('SUM(CASE WHEN product_listing_jobs.status = "processing" THEN 1 ELSE 0 END) as total_processing'),
                DB::raw('SUM(CASE WHEN product_listing_jobs.status = "done" AND DATE(product_listing_jobs.updated_at) = CURDATE() THEN 1 ELSE 0 END) as total_done_today'),
                DB::raw('SUM(CASE WHEN product_listing_jobs.status = "failed" THEN 1 ELSE 0 END) as total_failed'),
                DB::raw('(SELECT plj2.speed FROM product_listing_jobs plj2 WHERE plj2.client_id = product_listing_jobs.client_id AND plj2.status = "pending" GROUP BY plj2.speed ORDER BY COUNT(*) DESC LIMIT 1) as dominant_speed'),
            ])
            ->leftJoin('clients', 'clients.id', '=', 'product_listing_jobs.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'clients.user_id')
            ->groupBy('product_listing_jobs.client_id', 'u.name', 'u.email')
            ->orderByDesc('total_pending');
    }
}
