<?php

namespace App\Filament\Resources\ForbiddenWordResource\Pages;

use App\Filament\Resources\ForbiddenWordResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateForbiddenWord extends CreateRecord
{
    protected static string $resource = ForbiddenWordResource::class;
}
