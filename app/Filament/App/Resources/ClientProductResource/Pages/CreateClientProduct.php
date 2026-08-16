<?php

namespace App\Filament\App\Resources\ClientProductResource\Pages;

use App\Filament\App\Resources\ClientProductResource;
use App\Models\ClientProduct;
use Filament\Resources\Pages\CreateRecord;

class CreateClientProduct extends CreateRecord
{
    protected static string $resource = ClientProductResource::class;

    /**
     * Suporta multi-marketplace: cria um ClientProduct por conta selecionada.
     */
    protected function handleRecordCreation(array $data): ClientProduct
    {
        // marketplace_account_ids vem do CheckboxList (array); fallback pro select single
        $accountIds = array_filter((array) ($data['marketplace_account_ids'] ?? [$data['marketplace_account_id'] ?? null]));

        unset($data['marketplace_account_ids'], $data['marketplace_account_id']);

        $first = null;
        foreach ($accountIds as $accountId) {
            $record = static::getModel()::create(
                array_merge($data, ['marketplace_account_id' => $accountId])
            );
            if ($first === null) {
                $first = $record;
            }
        }

        return $first ?? static::getModel()::create($data);
    }

    /**
     * Apos criar, volta para a listagem em vez do form de edição.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
