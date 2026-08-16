<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-391 — ponte entre a extensão de LIVE e o seller.global.
 *
 * A extensão roda dentro do Console de LIVE do TikTok e manda o que só ela enxerga
 * (venda, comentário, aviso da plataforma). Aqui a gente registra e devolve o que só
 * nós temos (catálogo, custo, margem) — que é o diferencial contra as extensões do mercado.
 *
 * Sem migration: os eventos vão pra `audit_logs`, que já existe com a forma certa
 * (user_id, event, metadata, ip) e estava zerada.
 */
class LiveController extends Controller
{
    /** GET /api/v1/live/licenca — a extensão valida o código aqui. */
    public function licenca(Request $request): JsonResponse
    {
        $user   = $request->user();
        $client = $user?->client;

        $situacao = $this->situacaoAssinatura($client);

        // SEL-393: a extensao revalida por aqui. Bloqueou no painel -> ativo=false na hora.
        // MUL-312: licenca da extensao usa a mesma fonte do login. O comentario acima
        // ja prometia "bloqueou no painel -> ativo=false", mas blocked_at nunca era
        // checado aqui -- bloquear derrubava o painel e a extensao seguia valendo.
        $semAcesso = $user ? $user->motivoSemAcesso() : 'Sessao invalida.';
        if (! $situacao['ativa'] || $semAcesso !== null) {
            return response()->json([
                'ok'     => false,
                'ativo'  => false,
                'motivo' => $situacao['motivo'] ?? $semAcesso ?? 'conta bloqueada',
            ], 403);
        }

        return response()->json([
            'ok'         => true,
            'ativo'      => true,
            'seller'     => $user?->name,
            'client_id'  => $client?->id,
            'plano'      => $situacao['plano'],
            'recursos'   => [
                'som_de_venda'      => true,
                'ranking_produto'   => true,
                'margem_ao_vivo'    => (bool) $client,   // só com client dá pra calcular custo
                'aviso_plataforma'  => true,
            ],
        ]);
    }

    /** POST /api/v1/live/eventos — venda, aviso ou comentário vindos da extensão. */
    public function eventos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo'      => 'required|string|in:venda,aviso,comentario,inicio,fim',
            'comprador' => 'nullable|string|max:80',
            'produto'   => 'nullable|string|max:200',
            'valor'     => 'nullable|numeric|min:0',
            'texto'     => 'nullable|string|max:500',
            'em'        => 'nullable|numeric',
        ]);

        $user   = $request->user();
        $client = $user?->client;

        DB::table('audit_logs')->insert([
            'user_id'        => $user?->id,
            'event'          => 'live.' . $dados['tipo'],
            'auditable_type' => $client ? \App\Models\Client::class : null,
            'auditable_id'   => $client?->id,
            'metadata'       => json_encode($dados, JSON_UNESCAPED_UNICODE),
            'ip'             => $request->ip(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Aviso da plataforma é o evento que não pode passar batido.
        if ($dados['tipo'] === 'aviso') {
            Log::warning('[SEL-391] aviso da plataforma durante LIVE', [
                'user_id' => $user?->id,
                'texto'   => $dados['texto'] ?? null,
            ]);
        }

        $resposta = ['ok' => true];

        // O que a extensão NÃO consegue saber sozinha: custo e margem do que acabou de vender.
        if ($dados['tipo'] === 'venda' && $client && ! empty($dados['produto'])) {
            // SEL-391 fix: o custo NAO fica em client_products.supplier_unit_cost (NULL em
            // 1685/1685 anuncios). Ele vive em products.cost (589 de 645 preenchidos).
            // SEL-392: além do custo, devolve foto e ficha do produto — a extensão não
            // consegue essas coisas sozinha (ela só lê o texto da tela do TikTok).
            $achado = DB::table('client_products as cp')
                ->join('products as p', 'p.id', '=', 'cp.product_id')
                ->where('cp.client_id', $client->id)
                ->where(function ($q) use ($dados) {
                    $termo = '%' . mb_substr($dados['produto'], 0, 25) . '%';
                    $q->where('cp.custom_title', 'like', $termo)->orWhere('p.name', 'like', $termo);
                })
                ->orderByRaw('CASE WHEN p.cost > 0 THEN 0 ELSE 1 END')  // prioriza quem tem custo
                ->select([
                    'p.cost', 'p.name as nome_catalogo', 'p.sku',
                    'cp.custom_title', 'cp.custom_price', 'cp.image_url',
                    'cp.external_listing_url', 'cp.listing_status',
                ])
                ->first();

            if ($achado) {
                $resposta['produto_detalhe'] = [
                    'titulo'  => $achado->custom_title ?: $achado->nome_catalogo,
                    'sku'     => $achado->sku,
                    'imagem'  => $achado->image_url,
                    'preco'   => $achado->custom_price !== null ? (float) $achado->custom_price : null,
                    'anuncio' => $achado->external_listing_url,
                    'status'  => $achado->listing_status,
                ];

                $custo = $achado->cost;
                if ($custo !== null && (float) $custo > 0 && ! empty($dados['valor'])) {
                    $lucro = round(((float) $dados['valor']) - (float) $custo, 2);
                    $resposta['custo']  = (float) $custo;
                    $resposta['lucro']  = $lucro;
                    $resposta['margem'] = round($lucro / (float) $dados['valor'] * 100, 1);
                } else {
                    $resposta['aviso'] = 'produto sem custo cadastrado — margem indisponível';
                }
            } else {
                $resposta['aviso'] = 'não encontrei esse produto no seu catálogo';
            }
        }

        return response()->json($resposta);
    }

    /**
     * GET /api/v1/live/extensao — download da extensao, ATRELADO AO LOGIN.
     * O arquivo vive fora do docroot (storage/app/extensao), entao a unica forma
     * de baixar e passando pelo Sanctum. Sem token, sem download.
     */
    public function baixarExtensao(Request $request)
    {
        $user = $request->user();
        $caminho = storage_path('app/extensao/seller-global-live.zip');

        if (! is_file($caminho)) {
            return response()->json(['message' => 'Extensao indisponivel no momento.'], 404);
        }

        DB::table('audit_logs')->insert([
            'user_id'    => $user?->id,
            'event'      => 'live.download_extensao',
            'metadata'   => json_encode(['email' => $user?->email], JSON_UNESCAPED_UNICODE),
            'ip'         => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->download($caminho, 'seller-global-live.zip', [
            'Content-Type'  => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }


    /**
     * POST /api/v1/live/login — a extensao faz login com a MESMA conta do seller.global.
     *
     * Regra do Ruan (29/07): o acesso e atrelado a assinatura. Se o admin bloquear o
     * cliente ou a assinatura cair, a extensao para de entrar — nao precisa revogar
     * codigo, nao precisa avisar ninguem.
     */
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (! \Illuminate\Support\Facades\Auth::once($dados)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 401);
        }

        $user = \Illuminate\Support\Facades\Auth::user();

        // MUL-312: regra de acesso unica em User::motivoSemAcesso().
        if ($motivo = $user->motivoSemAcesso()) {
            return response()->json(['message' => $motivo], 403);
        }

        $client = $user->client;

        $situacao = $this->situacaoAssinatura($client);
        if (! $situacao['ativa']) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa. Regularize para usar a extensão.',
                'motivo'  => $situacao['motivo'],
            ], 402);
        }

        // token dedicado da extensao — da pra revogar so ele, sem derrubar o painel
        $token = $user->createToken('extensao-live')->plainTextToken;

        return response()->json([
            'ok'     => true,
            'token'  => $token,
            'seller' => $user->name,
            'plano'  => $situacao['plano'],
        ]);
    }

    /**
     * Assinatura ativa? Usada no login e na revalidacao.
     *
     * SEL-408 fix: antes pegava a assinatura mais RECENTEMENTE CRIADA (->latest())
     * e só depois checava o status — se essa fosse uma concessão já cancelada
     * (ex: acesso só-live revogado) enquanto o cliente tinha uma assinatura paga
     * ativa mais antiga, ele ficava bloqueado por engano. Agora busca primeiro
     * entre as ativas/trialing; só cai pra "motivo" quando não há nenhuma.
     * Isso também é o que faz o acesso só-live (SEL-408) e a concessão de
     * afiliado (SEL-386) funcionarem sem derrubar assinatura paga por perto.
     */
    private function situacaoAssinatura($client): array
    {
        if (! $client) {
            return ['ativa' => false, 'motivo' => 'conta sem cadastro de cliente', 'plano' => null];
        }

        $assinatura = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->whereNull('cancelled_at')
            ->with('plan')
            ->latest()
            ->first();

        if ($assinatura) {
            return ['ativa' => true, 'motivo' => null, 'plano' => optional($assinatura->plan)->slug];
        }

        $ultima = $client->subscriptions()->latest()->first();
        if (! $ultima) {
            return ['ativa' => false, 'motivo' => 'nenhuma assinatura encontrada', 'plano' => null];
        }

        return ['ativa' => false, 'motivo' => 'assinatura ' . $ultima->status, 'plano' => null];
    }


    /**
     * GET /api/v1/live/extensao/updates.xml — manifesto de atualizacao do Chrome.
     *
     * Publico de proposito: o Chrome busca isso sem cabecalho de auth. Ele so revela a
     * VERSAO disponivel; o download do .crx continua exigindo login. Sem isso nao ha
     * como corrigir bug nos clientes depois de distribuir.
     */
    public function updatesXml()
    {
        $versao = '0.2.0';
        $caminho = storage_path('app/extensao/manifest-version.txt');
        if (is_file($caminho)) {
            $versao = trim(file_get_contents($caminho)) ?: $versao;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<gupdate xmlns="http://www.google.com/update2/response" protocol="2.0">' . "\n"
             . '  <app appid="__ID_DA_EXTENSAO__">' . "\n"
             . '    <updatecheck codebase="https://api.seller.global/api/v1/live/extensao" version="'
             . htmlspecialchars($versao, ENT_QUOTES) . '" />' . "\n"
             . '  </app>' . "\n"
             . '</gupdate>';

        return response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

}
