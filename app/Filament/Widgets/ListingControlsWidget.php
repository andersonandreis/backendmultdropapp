<?php

namespace App\Filament\Widgets;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ListingControlsWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 1;
    protected static string $view = 'filament.widgets.listing-controls-widget';
    protected int|string|array $columnSpan = 'full';

    public array $data = [];

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public function mount(): void
    {
        $this->form->fill([
            'speed'         => 'normal',
            'generateImage' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('speed')
                    ->label('Velocidade Global')
                    ->options([
                        'slow'   => 'Lento (1 por minuto)',
                        'normal' => 'Normal (5 por minuto)',
                        'fast'   => 'Rapido (20 por minuto)',
                    ])
                    ->default('normal'),

                Toggle::make('generateImage')
                    ->label('Gerar Imagem com IA')
                    ->default(false),
            ])
            ->statePath('data');
    }

    public function applyGlobalSpeed(): void
    {
        try {
            if (Schema::hasTable('product_listing_jobs')) {
                $speed = $this->data['speed'] ?? 'normal';
                DB::table('product_listing_jobs')
                    ->where('status', 'pending')
                    ->update(['speed' => $speed, 'updated_at' => now()]);

                Notification::make()
                    ->title('Velocidade atualizada')
                    ->body("Todos os jobs pendentes definidos como: {$speed}")
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applyGenerateImage(): void
    {
        try {
            if (Schema::hasTable('product_listing_jobs')) {
                $generateImage = (bool) ($this->data['generateImage'] ?? false);
                DB::table('product_listing_jobs')
                    ->where('status', 'pending')
                    ->update(['generate_image' => $generateImage, 'updated_at' => now()]);

                Notification::make()
                    ->title('Configuracao atualizada')
                    ->body('Geracao de imagem: ' . ($generateImage ? 'ativada' : 'desativada'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function enqueueAll(): void
    {
        try {
            Artisan::call('hubai:enqueue-listings');
            $output = Artisan::output();

            Notification::make()
                ->title('Enfileiramento iniciado')
                ->body(trim($output) ?: 'Comando executado com sucesso.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao enfileirar')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
