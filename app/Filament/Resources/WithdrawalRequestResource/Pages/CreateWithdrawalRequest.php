<?php

namespace App\Filament\Resources\WithdrawalRequestResource\Pages;

use App\Filament\Resources\WithdrawalRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWithdrawalRequest extends CreateRecord
{
    protected static string $resource = WithdrawalRequestResource::class;
}
