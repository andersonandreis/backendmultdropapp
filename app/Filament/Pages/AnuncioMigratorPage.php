<?php

namespace App\Filament\Pages;

use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\AnuncioMigratorService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** NOV-134 — Página de migração de anúncios. */
class AnuncioMigratorPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Ferramentas';
    protected static ?string $title = 'Migrar Anúncio entre Contas';
    protected static ?string $slug = 'migrar-anuncio';
    protected static string $view = 'filament.pages.anuncio-migrator';
    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('from_account_id')
                ->label('Conta de origem')
                ->options(fn () => MarketplaceAccount::query()
                    ->where('platform', 'mercadolivre')
                    ->where('status', 'active')
                    ->get()
                    ->mapWithKeys(fn ($a) => [$a->id => ($a->account_name ?: 'Conta #'.$a->id).' ('.($a->seller_nickname ?: '-').')'])
                    ->all())
                ->required()->searchable(),
            Select::make('to_account_id')
                ->label('Conta de destino')
                ->options(fn () => MarketplaceAccount::query()
                    ->where('platform', 'mercadolivre')
                    ->where('status', 'active')
                    ->get()
                    ->mapWithKeys(fn ($a) => [$a->id => ($a->account_name ?: 'Conta #'.$a->id).' ('.($a->seller_nickname ?: '-').')'])
                    ->all())
                ->required()->searchable(),
            TextInput::make('listing_id')
                ->label('ID do anúncio (ex: MLB-1234567890)')
                ->required(),
        ])->statePath('data');
    }

    public function migrate(): void
    {
        $data = $this->form->getState();
        $svc = app(AnuncioMigratorService::class);
        $out = $svc->migrate(
            (int) $data['from_account_id'],
            (int) $data['to_account_id'],
            (string) $data['listing_id']
        );
        if ($out['ok']) {
            Notification::make()
                ->title('Migração OK')
                ->body('Novo anúncio: '.($out['new_listing_id'] ?? '-'))
                ->success()->send();
            $this->form->fill();
        } else {
            Notification::make()->title('Falha')->body($out['message'])->danger()->send();
        }
    }
}
