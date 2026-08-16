<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'suppliers';

    protected static ?string $title = 'Fornecedores Vinculados';

    protected static ?string $modelLabel = 'Fornecedor';

    protected static ?string $pluralModelLabel = 'Fornecedores';

    /**
     * Retorna os suppliers do plano ativo do client (somente leitura, nao gerenciados aqui).
     */
    private function getPlanSupplierIds(): array
    {
        $client = $this->getOwnerRecord();
        $sub = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.suppliers')
            ->latest()
            ->first();

        return $sub?->plan?->suppliers?->pluck('id')->toArray() ?? [];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')
                ->label('Fornecedor')
                ->options(function () {
                    $planIds = $this->getPlanSupplierIds();
                    // Exclui ja vinculados (plano + extra) e nao ativos
                    $linkedIds = $this->getOwnerRecord()
                        ->suppliers()
                        ->pluck('suppliers.id')
                        ->toArray();

                    return Supplier::where('is_active', true)
                        ->whereNotIn('id', array_merge($planIds, $linkedIds))
                        ->orderBy('company_name')
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => $s->display_name ?: $s->company_name]);
                })
                ->required()
                ->searchable()
                ->placeholder('Selecione um fornecedor...'),

            Forms\Components\Hidden::make('is_extra')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        $planSupplierIds = $this->getPlanSupplierIds();

        return $table
            ->recordTitleAttribute('company_name')
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('city')
                    ->label('Cidade')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\BadgeColumn::make('origem')
                    ->label('Origem')
                    ->getStateUsing(function ($record) use ($planSupplierIds) {
                        return in_array($record->id, $planSupplierIds) ? 'plano' : 'extra';
                    })
                    ->colors([
                        'primary' => 'plano',
                        'success' => 'extra',
                    ])
                    ->icons([
                        'heroicon-o-credit-card' => 'plano',
                        'heroicon-o-plus-circle' => 'extra',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Adicionar Fornecedor Extra')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query
                        ->where('is_active', true)
                        ->orderBy('company_name')
                    )
                    ->recordSelectSearchColumns(['company_name', 'display_name'])
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Fornecedor')
                            ->placeholder('Pesquise pelo nome...'),
                        Forms\Components\Hidden::make('is_extra')
                            ->default(true),
                    ])
                    ->before(function (array $data) {
                        // Bloqueia re-vinculo de fornecedor ja no plano
                        $planIds = $this->getPlanSupplierIds();
                        if (in_array($data['recordId'], $planIds)) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('Fornecedor ja incluso no plano')
                                ->body('Este fornecedor ja esta disponivel para este lojista via plano. Nao e necessario adicionar como extra.')
                                ->send();

                            $this->halt();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Remover')
                    ->visible(function ($record) use ($planSupplierIds) {
                        // Permite remover apenas extras — nunca os do plano
                        return !in_array($record->id, $planSupplierIds)
                            && $record->pivot?->is_extra;
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Remover fornecedor extra')
                    ->modalDescription('Este fornecedor extra sera removido do lojista. Os fornecedores herdados do plano nao sao afetados.'),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()
                    ->label('Remover selecionados')
                    ->requiresConfirmation(),
            ])
            ->emptyStateHeading('Nenhum fornecedor vinculado')
            ->emptyStateDescription('Clique em Adicionar Fornecedor Extra para vincular um fornecedor adicional alem dos inclusos no plano.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }
}
