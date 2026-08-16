<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AtendimentoSAC extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup = 'Suporte';
    protected static ?string $navigationLabel = 'Atendimento (SAC)';
    protected static ?string $title = 'Central de Atendimento SAC';
    protected static ?string $slug = 'atendimento-sac';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.atendimento-sac';

    public array $chamados = [];
    public string $categoriaFiltro = 'todos';

    public function mount(): void
    {
        $this->carregarChamados();
    }

    public function carregarChamados(): void
    {
        if (\Schema::hasTable('support_tickets')) {
            // MUL-269 fase 2: seller_name vem do user (clients.company_name removido).
            $query = \DB::table('support_tickets')
                ->join('clients', 'support_tickets.client_id', '=', 'clients.id')
                ->leftJoin('users', 'users.id', '=', 'clients.user_id')
                ->select('support_tickets.*', \DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as seller_name"))
                ->orderByDesc('support_tickets.created_at')
                ->limit(100);

            if ($this->categoriaFiltro !== 'todos') {
                $query->where('support_tickets.category', $this->categoriaFiltro);
            }

            $this->chamados = $query->get()->toArray();
        } else {
            $this->chamados = [];
        }
    }

    public function filtrarCategoria(string $categoria): void
    {
        $this->categoriaFiltro = $categoria;
        $this->carregarChamados();
    }

    public function getCategorias(): array
    {
        return [
            'todos'       => 'Todos',
            'product'     => 'Produto',
            'delivery'    => 'Entrega',
            'financial'   => 'Financeiro',
            'integration' => 'Integracao',
            'other'       => 'Outros',
        ];
    }

    public function getLabelStatus(string $status): string
    {
        return match ($status) {
            'new'         => 'Novo',
            'in_progress' => 'Em Andamento',
            'resolved'    => 'Resolvido',
            'closed'      => 'Fechado',
            default       => ucfirst($status),
        };
    }

    public function getLabelCategoria(string $cat): string
    {
        return match ($cat) {
            'product'     => 'Produto',
            'delivery'    => 'Entrega',
            'financial'   => 'Financeiro',
            'integration' => 'Integracao',
            'other'       => 'Outros',
            default       => ucfirst($cat),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            // --- Botao principal: Abrir Chamado no SAC HubAI (Supabase) ---
            Action::make('abrir_chamado_sac')
                ->label('Abrir Chamado SAC')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('danger')
                ->modalHeading('Abrir Chamado no SAC HubAI')
                ->modalDescription('O chamado sera criado no sistema SAC HubAI e atendido pelo robo automaticamente.')
                ->modalSubmitActionLabel('Abrir Chamado')
                ->form([
                    Select::make('client_id')
                        ->label('Cliente (Lojista)')
                        // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                        ->options(function () {
                            return \App\Models\Client::query()
                                ->join('users', 'users.id', '=', 'clients.user_id')
                                ->orderByRaw("COALESCE(NULLIF(users.full_name,''), users.name)")
                                ->select('clients.id', \DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as label"))
                                ->pluck('label', 'clients.id')
                                ->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Selecione o lojista que esta abrindo o chamado'),

                    Select::make('category')
                        ->label('Categoria')
                        ->options([
                            'painel_bug'  => 'Bug no Painel',
                            'integracao'  => 'Integracao (ML/Shopee/Bling)',
                            'pedido'      => 'Pedido / Envio',
                            'financeiro'  => 'Financeiro / Pagamento',
                            'produto'     => 'Produto / Catalogo',
                            'acesso'      => 'Acesso / Login',
                            'outros'      => 'Outros',
                        ])
                        ->default('outros')
                        ->required()
                        ->native(false),

                    Select::make('priority')
                        ->label('Prioridade')
                        ->options([
                            'LOW'    => 'Baixa',
                            'MEDIUM' => 'Media',
                            'HIGH'   => 'Alta',
                            'URGENT' => 'Urgente',
                        ])
                        ->default('MEDIUM')
                        ->required()
                        ->native(false),

                    TextInput::make('assunto')
                        ->label('Assunto')
                        ->required()
                        ->maxLength(200)
                        ->placeholder('Ex: Erro ao publicar produto no Mercado Livre'),

                    Textarea::make('description')
                        ->label('Descricao detalhada')
                        ->required()
                        ->rows(5)
                        ->maxLength(5000)
                        ->placeholder('Descreva o problema: o que aconteceu, quando, qual erro apareceu...'),
                ])
                ->action(function (array $data): void {
                    $this->criarChamadoSAC($data);
                }),

            // --- Botao secundario: chamado interno (support_tickets local) ---
            Action::make('novo_chamado_interno')
                ->label('Chamado Interno')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->modalHeading('Novo Chamado Interno')
                ->form([
                    Select::make('client_id')
                        ->label('Seller')
                        // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                        ->options(function () {
                            return \App\Models\Client::query()
                                ->join('users', 'users.id', '=', 'clients.user_id')
                                ->orderByRaw("COALESCE(NULLIF(users.full_name,''), users.name)")
                                ->select('clients.id', \DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as label"))
                                ->pluck('label', 'clients.id')
                                ->toArray();
                        })
                        ->searchable()
                        ->required(),

                    TextInput::make('title')
                        ->label('Titulo')
                        ->required()
                        ->maxLength(200),

                    Select::make('category')
                        ->label('Categoria')
                        ->options([
                            'product'     => 'Produto',
                            'delivery'    => 'Entrega',
                            'financial'   => 'Financeiro',
                            'integration' => 'Integracao',
                            'other'       => 'Outros',
                        ])
                        ->default('other')
                        ->required()
                        ->native(false),

                    Select::make('priority')
                        ->label('Prioridade')
                        ->options([
                            'low'    => 'Baixa',
                            'medium' => 'Media',
                            'high'   => 'Alta',
                        ])
                        ->default('medium')
                        ->required()
                        ->native(false),

                    Textarea::make('description')
                        ->label('Descricao')
                        ->required()
                        ->rows(4)
                        ->maxLength(5000),
                ])
                ->action(function (array $data): void {
                    if (\Schema::hasTable('support_tickets')) {
                        $ticketId = \DB::table('support_tickets')->insertGetId([
                            'client_id'   => $data['client_id'],
                            'title'       => $data['title'],
                            'category'    => $data['category'] ?? 'other',
                            'priority'    => $data['priority'] ?? 'medium',
                            'status'      => 'new',
                            'description' => $data['description'],
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);

                        if (!empty($data['description']) && \Schema::hasTable('support_ticket_messages')) {
                            \DB::table('support_ticket_messages')->insert([
                                'ticket_id'      => $ticketId,
                                'author_type'    => 'admin',
                                'author_user_id' => auth()->id(),
                                'body'           => $data['description'],
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ]);
                        }

                        $this->carregarChamados();
                    }

                    Notification::make()->title('Chamado interno criado!')->success()->send();
                }),
        ];
    }

    /**
     * Cria chamado no SAC HubAI via Supabase REST API.
     * origin=whitelabel, whitelabel_slug=APP_TENANT (env)
     * O robo SAC monitora sac_tickets e atende automaticamente.
     */
    protected function criarChamadoSAC(array $data): void
    {
        $client = \App\Models\Client::find($data['client_id']);
        if (!$client) {
            Notification::make()->title('Cliente nao encontrado')->danger()->send();
            return;
        }

        $user          = \App\Models\User::find($client->user_id ?? null);
        $customerEmail = $user?->email ?? ('lojista+' . $client->id . '@' . env('APP_TENANT', 'hubai') . '.io');
        $customerName  = $client->company_name ?? $client->name ?? 'Lojista ' . config('app.name');
        $customerPhone = $client->phone ?? null;

        $supabaseUrl = rtrim(env('SUPABASE_URL', 'https://omvstizxjosygkcolzzl.supabase.co'), '/');
        $supabaseKey = env('SUPABASE_ANON_KEY', '');

        $payload = [
            'customer_email'    => $customerEmail,
            'customer_name'     => $customerName,
            'customer_phone'    => $customerPhone,
            'customer_plan'     => env('APP_TENANT', 'hubai'),
            'category'          => $data['category'],
            'description'       => $data['assunto'] . "\n\n" . $data['description'],
            'status'            => 'NEW',
            'priority'          => $data['priority'],
            'origin'            => 'whitelabel',
            'whitelabel_slug'   => env('APP_TENANT', 'hubai'),
            'checklist_answers' => [
                'wl_nome'     => config('app.name'),
                'source_type' => 'admin_painel',
                'client_id'   => $client->id,
                'assunto'     => $data['assunto'],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'apikey'        => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ])->post($supabaseUrl . '/rest/v1/sac_tickets', $payload);

            if ($response->successful()) {
                $ticket       = $response->json()[0] ?? [];
                $ticketNumber = $ticket['ticket_number'] ?? '-';

                Notification::make()
                    ->title("Chamado SAC #{$ticketNumber} aberto!")
                    ->body("Robo SAC HubAI ira atender em breve. Cliente: {$customerName}")
                    ->success()
                    ->persistent()
                    ->send();

                Log::info('SAC ' . config('app.name') . ': ticket criado', [
                    'ticket_number' => $ticketNumber,
                    'client_id'     => $client->id,
                    'client_name'   => $customerName,
                    'category'      => $data['category'],
                ]);
            } else {
                $errorBody = $response->body();
                Log::error('SAC ' . config('app.name') . ': erro ao criar ticket', [
                    'status' => $response->status(),
                    'body'   => $errorBody,
                ]);

                Notification::make()
                    ->title('Erro ao abrir chamado SAC')
                    ->body('HTTP ' . $response->status() . ': ' . substr($errorBody, 0, 200))
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('SAC ' . config('app.name') . ': excecao', ['error' => $e->getMessage()]);

            Notification::make()
                ->title('Erro de conexao com SAC')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}