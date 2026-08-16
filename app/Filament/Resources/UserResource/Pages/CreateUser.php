<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Client;
use App\Models\Subscription;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Remove campos virtuais que nao pertencem a tabela users
        unset(
            $data['plan_id'],
            $data['subscription_status'],
            $data['subscription_payment_method'],
            $data['subscription_period_end'],
        );

        // Garante email_verified_at preenchido: admin cria usuario ja verificado (FOR-022)
        if (empty($data['email_verified_at'])) {
            $data['email_verified_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $formData = $this->form->getState();
        $planId = $formData['plan_id'] ?? null;

        if ($this->record->role === 'client') {
            // Cria o Client vinculado ao User
            // O UserObserver tambem faz firstOrCreate — usando o mesmo para ser idempotente
            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = Client::firstOrCreate(
                ['user_id' => $this->record->id],
                [
                    'document' => '00000000000',
                    'is_active' => true,
                ],
            );

            if ($planId) {
                // Plano selecionado: criar subscription com os dados do formulario
                Subscription::create([
                    'client_id' => $client->id,
                    'plan_id' => $planId,
                    'status' => $formData['subscription_status'] ?? 'active',
                    'payment_method' => $formData['subscription_payment_method'] ?? 'manual',
                    'current_period_start' => now(),
                    'current_period_end' => $formData['subscription_period_end']
                        ?? now()->addMonth(),
                ]);
            } else {
                // Sem plano selecionado: criar subscription trialing por 30 dias para acesso imediato
                if ($client->subscriptions()->count() === 0) {
                    Subscription::create([
                        'client_id' => $client->id,
                        'plan_id' => null,
                        'status' => 'trialing',
                        'payment_method' => 'manual',
                        'trial_ends_at' => now()->addDays(30),
                        'current_period_start' => now(),
                        'current_period_end' => now()->addDays(30),
                    ]);
                }
            }
        }
    }
}
