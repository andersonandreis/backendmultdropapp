<?php

namespace App\Filament\Pages;

use App\Models\Client;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class MensagensSellers extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Clientes & Sellers';
    protected static ?string $navigationLabel = 'Mensagens para Sellers';
    protected static ?string $title = 'Mensagens para Sellers';
    protected static ?string $slug = 'mensagens-sellers';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.mensagens-sellers';

    public ?array $data = [];
    public array $mensagens = [];

    public function mount(): void
    {
        $this->carregarMensagens();
    }

    public function carregarMensagens(): void
    {
        // SEL-076: supplier_messages nao tem client_id, tem recipient_id (com recipient_type=client).
        // JOIN + LEFT pra suportar recipient_type=all (recipient_id=null).
        if (\Schema::hasTable('supplier_messages')) {
            // MUL-269 fase 2: seller_name vem do user (clients.company_name removido).
            $this->mensagens = \DB::table('supplier_messages')
                ->leftJoin('clients', function ($j) {
                    $j->on('supplier_messages.recipient_id', '=', 'clients.id')
                      ->where('supplier_messages.recipient_type', '=', 'client');
                })
                ->leftJoin('users', 'users.id', '=', 'clients.user_id')
                ->select('supplier_messages.*', \DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as seller_name"))
                ->orderByDesc('supplier_messages.created_at')
                ->limit(100)
                ->get()
                ->toArray();
        } else {
            $this->mensagens = [];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('nova_mensagem')
                ->label('Nova Mensagem')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\Select::make('client_id')
                        ->label('Destinatário (Seller)')
                        // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                        ->options(function () {
                            return Client::query()
                                ->join('users', 'users.id', '=', 'clients.user_id')
                                ->orderByRaw("COALESCE(NULLIF(users.full_name,''), users.name)")
                                ->select('clients.id', \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as label"))
                                ->pluck('label', 'clients.id')
                                ->toArray();
                        })
                        ->searchable()
                        ->required(),

                    \Filament\Forms\Components\TextInput::make('assunto')
                        ->label('Assunto')
                        ->required()
                        ->maxLength(255),

                    \Filament\Forms\Components\Textarea::make('mensagem')
                        ->label('Mensagem')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    // SEL-076: schema real de supplier_messages tem recipient_id/recipient_type/channel/status
                    // — nao client_id/direction/read_at. Fallback supplier_id: primeiro supplier existente
                    // (evita FK 1452 quando super_admin sem supplier proprio).
                    if (\Schema::hasTable('supplier_messages')) {
                        $supplierId = auth()->user()?->supplier?->id
                            ?? (int) (\DB::table('suppliers')->orderBy('id')->value('id') ?? 0);

                        if ($supplierId > 0) {
                            \DB::table('supplier_messages')->insert([
                                'supplier_id'      => $supplierId,
                                'recipient_type'   => 'client',
                                'recipient_id'     => $data['client_id'],
                                'channel'          => 'email',
                                'subject'          => $data['assunto'],
                                'body'             => $data['mensagem'],
                                'status'           => 'pending',
                                'recipients_count' => 1,
                                'created_by_user_id' => auth()->id(),
                                'created_at'       => now(),
                                'updated_at'       => now(),
                            ]);
                            $this->carregarMensagens();
                        }
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('Mensagem enviada!')
                        ->success()
                        ->send();
                }),
        ];
    }
}
