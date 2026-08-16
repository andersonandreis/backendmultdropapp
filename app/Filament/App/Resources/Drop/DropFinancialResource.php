<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropFinancialResource\Pages;
use App\Models\Drop\DropProfitReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class DropFinancialResource extends Resource
{
    protected static ?string $model = DropProfitReport::class;
    protected static ?string $slug = 'drop/financeiro';
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 7;
    protected static ?string $navigationLabel = 'Financeiro Drop';
    protected static ?string $modelLabel = 'Relatorio Financeiro';
    protected static ?string $pluralModelLabel = 'Financeiro Drop';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'super_admin') {
            return $query;
        }

        $clientId = $user->client?->id;
        if ($clientId) {
            $plan = $user->client->subscription?->plan ?? null;
            if ($plan && isset($plan->has_drop_internacional) && !$plan->has_drop_internacional) {
                Notification::make()
                    ->title('Modulo Drop Internacional')
                    ->body('Seu plano atual nao inclui o modulo Drop Internacional. Fale com o suporte para fazer upgrade.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label('Periodo')
                    ->getStateUsing(fn (DropProfitReport $record): string =>
                        \Carbon\Carbon::parse($record->period_start)->format('d/m/y')
                        . ' - '
                        . \Carbon\Carbon::parse($record->period_end)->format('d/m/y')
                    )
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('period_start', $direction)),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Pedidos')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Receita Total')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_cost_product')
                    ->label('Custo Produtos')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_profit')
                    ->label('Lucro Liquido')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->sortable(),
                Tables\Columns\TextColumn::make('profitable_orders')
                    ->label('Com Lucro')
                    ->numeric()
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('loss_orders')
                    ->label('Com Prejuizo')
                    ->numeric()
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Gerado')
                    ->since()
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\Action::make('gerar_relatorio')
                    ->label('Gerar Relatorio')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('primary')
                    ->form([
                        Forms\Components\DatePicker::make('period_start')
                            ->label('Data Inicio')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('period_end')
                            ->label('Data Fim')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('period_start'),
                    ])
                    ->action(function (array $data) {
                        try {
                            $response = Http::timeout(60)
                                ->post(config('app.url') . '/api/v1/drop/financial/report', [
                                    'period_start' => $data['period_start'],
                                    'period_end'   => $data['period_end'],
                                ]);

                            if ($response->successful()) {
                                Notification::make()
                                    ->title('Relatorio gerado!')
                                    ->body('O relatorio financeiro foi gerado com sucesso.')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Erro ao gerar relatorio')
                                    ->body($response->json('message') ?? 'Tente novamente.')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao gerar relatorio')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Ver detalhes'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->defaultSort('period_start', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropProfitReports::route('/'),
        ];
    }
}
