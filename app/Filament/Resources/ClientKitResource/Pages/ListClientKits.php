<?php
namespace App\Filament\Resources\ClientKitResource\Pages;
use App\Filament\Resources\ClientKitResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListClientKits extends ListRecords {
    protected static string $resource = ClientKitResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
