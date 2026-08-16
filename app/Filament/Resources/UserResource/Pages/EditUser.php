<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Client;
use App\Models\Subscription;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Carrega os dados da assinatura ativa para preencher o formulário
        $client = $this->record->client;

        if ($client) {
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            if ($subscription) {
                $data['plan_id'] = $subscription->plan_id;
                $data['subscription_status'] = $subscription->status;
                $data['subscription_payment_method'] = $subscription->payment_method;
                $data['subscription_period_end'] = $subscription->current_period_end;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset(
            $data['plan_id'],
            $data['subscription_status'],
            $data['subscription_payment_method'],
            $data['subscription_period_end'],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        $formData = $this->form->getState();
        $planId = $formData['plan_id'] ?? null;

        if ($this->record->role !== 'client') {
            return;
        }

        if ($planId) {
            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = Client::firstOrCreate(
                ['user_id' => $this->record->id],
                [
                    'document' => '00000000000',
                    'is_active' => true,
                ],
            );

            // Atualiza a assinatura existente ou cria uma nova
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            if ($subscription) {
                $subscription->update([
                    'plan_id' => $planId,
                    'status' => $formData['subscription_status'] ?? $subscription->status,
                    'payment_method' => $formData['subscription_payment_method'] ?? $subscription->payment_method,
                    'current_period_end' => $formData['subscription_period_end'] ?? $subscription->current_period_end,
                ]);
            } else {
                Subscription::create([
                    'client_id' => $client->id,
                    'plan_id' => $planId,
                    'status' => $formData['subscription_status'] ?? 'active',
                    'payment_method' => $formData['subscription_payment_method'] ?? 'manual',
                    'current_period_start' => now(),
                    'current_period_end' => $formData['subscription_period_end']
                        ?? now()->addMonth(),
                ]);
            }
        }
    }
}
