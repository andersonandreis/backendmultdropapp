<?php

namespace App\Filament\Resources\TenantApiCredentialResource\Pages;

use App\Filament\Resources\TenantApiCredentialResource;
use App\Models\TenantApiCredential;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateTenantApiCredential extends CreateRecord
{
    protected static string $resource = TenantApiCredentialResource::class;

    /** O token bruto, gerado no handleRecordCreation, exibido na notificacao apos. */
    protected ?string $rawToken = null;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $secret = Str::random(48);
        $keyId  = 'ht_live_' . Str::random(16);
        $this->rawToken = $keyId . '.' . $secret;

        $record = TenantApiCredential::create([
            'tenant_id' => $data['tenant_id'],
            'key_id'    => $keyId,
            'key_hash'  => password_hash($secret, PASSWORD_BCRYPT),
            'scopes'    => $data['scopes'],
        ]);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Credencial criada — COPIE O TOKEN AGORA')
            ->body("Token: {$this->rawToken}\n\nEsta e a UNICA vez que ele aparece. Armazene em local seguro e entregue ao whitelabel via canal protegido.")
            ->success()
            ->persistent();
    }
}
