<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function afterCreate(): void
    {
        $client = $this->record;
        $user   = $client->user;

        if (!$user) {
            return;
        }

        Notification::make()
            ->title('Lojista criado com sucesso!')
            ->body(
                "**E-mail de acesso:** {$user->email}\n" .
                "**Painel do Seller:** " . url('/app') . "\n\n" .
                "Compartilhe essas informações com o cliente."
            )
            ->success()
            ->persistent()
            ->send();
    }
}
