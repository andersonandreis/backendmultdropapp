<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookDeliveryResource\Pages;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;
    protected static ?string $slug = 'webhook-deliveries';
    protected static ?string $modelLabel = 'Webhook Delivery';
    protected static ?string $pluralModelLabel = 'Webhook Deliveries';
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Supplier Core';
    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool { return auth()->user()?->role === 'super_admin'; }
    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function form(Form $form): Form { return $form->schema([]); }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('endpoint.tenant.slug')->label('Tenant')->sortable(),
                Tables\Columns\TextColumn::make('event')->searchable(),
                Tables\Columns\BadgeColumn::make('status')->colors([
                    'gray'    => 'pending',
                    'success' => 'success',
                    'warning' => 'failed',
                    'danger'  => 'dead',
                ]),
                Tables\Columns\TextColumn::make('attempt')->label('Attempt'),
                Tables\Columns\TextColumn::make('response_code')->label('HTTP'),
                Tables\Columns\TextColumn::make('next_retry_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'pending', 'success' => 'success', 'failed' => 'failed', 'dead' => 'dead',
                ]),
                Tables\Filters\SelectFilter::make('event')->options(fn () => Cache::remember('webhook_events_distinct', 600, fn () => WebhookDelivery::query()->distinct()->pluck('event', 'event')->toArray())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalContent(fn ($record) => view('filament.delivery-view', ['record' => $record])),
                Tables\Actions\Action::make('retry')
                    ->label('Re-enviar')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn ($record) => in_array($record->status, ['failed', 'dead']))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->forceFill([
                            'status'        => WebhookDelivery::STATUS_PENDING,
                            'next_retry_at' => null,
                        ])->save();
                        \App\Jobs\DispatchWebhookJob::dispatch($record->id)->onQueue(\App\Jobs\DispatchWebhookJob::queueFor($record));
                        Notification::make()->title('Re-enviada')->body('Job de dispatch enfileirado.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookDeliveries::route('/'),
        ];
    }
}
