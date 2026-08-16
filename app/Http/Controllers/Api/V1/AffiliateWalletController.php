<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Affiliate\AffiliateWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-CARTEIRA-AFILIADO (14/08) — a carteira do afiliado, pedida pelo Ruan na live.
 *
 * O afiliado poe saldo por PIX (QR code) e usa esse saldo pra criar usuarios na
 * plataforma pagando METADE do plano — porque a comissao dele e 50%, e o Ruan fica
 * com metade da PRIMEIRA mensalidade.
 *
 * Do lado do Ruan: quem pagou quanto, quanto tem de saldo, quanto saiu, por afiliado.
 */
class AffiliateWalletController extends Controller
{
    public function __construct(private AffiliateWalletService $carteira)
    {
    }

    /** O afiliado do usuario logado. 403 se ele nao for afiliado aprovado. */
    private function afiliadoDoRequest(Request $r): object
    {
        $aff = DB::table('affiliates')->where('user_id', $r->user()->id)->first();
        if (! $aff) {
            abort(response()->json(['message' => 'Voce ainda nao e afiliado.'], 403));
        }

        return $aff;
    }

    // ------------------------------------------------------------------ afiliado

    /** GET /v1/affiliate/wallet — saldo, extrato e a tabela de precos. */
    public function index(Request $r): JsonResponse
    {
        $aff = $this->afiliadoDoRequest($r);
        $w   = $this->carteira->carteira((int) $aff->id);

        return response()->json([
            'saldo'             => round($w->balance_cents / 100, 2),
            'saldo_cents'       => (int) $w->balance_cents,
            'total_depositado'  => round($w->lifetime_deposited_cents / 100, 2),
            'total_gasto'       => round($w->lifetime_spent_cents / 100, 2),
            'planos'            => $this->carteira->tabelaDePrecos(),
            'extrato'           => $this->carteira->extrato((int) $aff->id, 50),
            'deposito_minimo'   => AffiliateWalletService::DEPOSITO_MINIMO_CENTS / 100,
        ]);
    }

    /** POST /v1/affiliate/wallet/deposit — gera o QR code do PIX. */
    public function depositar(Request $r): JsonResponse
    {
        $r->validate([
            'valor'    => 'required|numeric|min:10|max:50000',
            'documento' => 'nullable|string|max:20',
        ]);

        $aff  = $this->afiliadoDoRequest($r);
        $user = $r->user();

        // O Pagar.me exige documento valido no cliente. Usa o do cadastro; se nao
        // tiver, aceita o que veio no request (o front pede uma vez so).
        $client = DB::table('clients')->where('user_id', $user->id)->first();
        $doc    = preg_replace('/\D/', '', (string) ($r->input('documento') ?: ($client->document ?? '')));
        if (strlen($doc) !== 11 && strlen($doc) !== 14) {
            return response()->json([
                'message'         => 'Pra gerar o PIX preciso do seu CPF ou CNPJ.',
                'precisa_documento' => true,
            ], 422);
        }

        $dadosCliente = [
            'name'     => $aff->application_name ?: $user->name,
            'email'    => $aff->application_email ?: $user->email,
            'document' => $doc,
            'type'     => strlen($doc) === 11 ? 'individual' : 'company',
            // SEL-PIX-TELEFONE: o Pagar.me RECUSA a ordem sem telefone — e recusa
            // devolvendo HTTP 200 com status "failed" e QR VAZIO, entao sem isso o
            // afiliado ficava olhando pra uma tela sem codigo. Peguei no 1o teste real.
            '_telefone' => $aff->application_phone ?: ($client->phone ?? null),
        ];

        try {
            $out = $this->carteira->gerarDeposito(
                (int) $aff->id,
                (int) round(((float) $r->input('valor')) * 100),
                $dadosCliente
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Valor fora do permitido.', 'erro' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('[SEL-CARTEIRA] falha ao gerar deposito', [
                'affiliate_id' => $aff->id, 'erro' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Nao consegui gerar o PIX agora. Tente de novo em instantes.'], 502);
        }

        // guarda o documento pra ele nao ter que digitar de novo
        if ($client && empty($client->document)) {
            DB::table('clients')->where('id', $client->id)->update(['document' => $doc, 'updated_at' => now()]);
        }

        return response()->json($out, 201);
    }

    /** GET /v1/affiliate/wallet/deposit/{id} — o front fica perguntando ate pagar. */
    public function statusDoDeposito(Request $r, int $id): JsonResponse
    {
        $aff = $this->afiliadoDoRequest($r);
        $dep = DB::table('affiliate_wallet_deposits')
            ->where('id', $id)->where('affiliate_id', $aff->id)->first();

        if (! $dep) {
            return response()->json(['message' => 'Deposito nao encontrado.'], 404);
        }

        // expirou sem pagar? marca, pra nao ficar "pendente" pra sempre na tela
        if ($dep->status === 'pending' && $dep->expires_at && strtotime($dep->expires_at) < time()) {
            DB::table('affiliate_wallet_deposits')->where('id', $id)
                ->update(['status' => 'expired', 'updated_at' => now()]);
            $dep->status = 'expired';
        }

        return response()->json([
            'deposito_id'  => $dep->id,
            'status'       => $dep->status,
            'valor'        => round($dep->amount_cents / 100, 2),
            'copia_e_cola' => $dep->qr_code,
            'qr_code_url'  => $dep->qr_code_url,
            'pago_em'      => $dep->paid_at,
        ]);
    }

    /** POST /v1/affiliate/wallet/create-user — cria o cliente debitando a carteira. */
    public function criarUsuario(Request $r): JsonResponse
    {
        $r->validate([
            'plano'    => 'required|string|in:' . implode(',', AffiliateWalletService::PLANOS_VENDAVEIS),
            'nome'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'senha'    => 'required|string|min:6',
            'telefone' => 'nullable|string|max:20',
        ]);

        $aff = $this->afiliadoDoRequest($r);

        try {
            $out = $this->carteira->criarUsuario(
                (int) $aff->id,
                (string) $r->input('plano'),
                (string) $r->input('nome'),
                (string) $r->input('email'),
                (string) $r->input('senha'),
                $r->input('telefone')
            );
        } catch (\RuntimeException $e) {
            $msg = match ($e->getMessage()) {
                'saldo_insuficiente' => 'Saldo insuficiente. Adicione credito na carteira antes de criar o usuario.',
                'email_ja_existe'    => 'Ja existe conta com esse e-mail.',
                default              => 'Nao consegui criar o usuario agora.',
            };
            $http = $e->getMessage() === 'saldo_insuficiente' ? 402 : 409;

            return response()->json(['message' => $msg, 'erro' => $e->getMessage()], $http);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Plano invalido.'], 422);
        }

        return response()->json($out, 201);
    }

    // --------------------------------------------------------------------- admin

    private function exigeAdmin(Request $r): void
    {
        if (! in_array($r->user()->role ?? '', ['admin', 'super_admin'], true)) {
            abort(response()->json(['message' => 'Sem permissao.'], 403));
        }
    }

    /** GET /v1/admin/affiliate-wallets — o controle do Ruan: quem pagou, quanto tem, quanto saiu. */
    public function adminLista(Request $r): JsonResponse
    {
        $this->exigeAdmin($r);

        $linhas = DB::table('affiliate_wallets as w')
            ->join('affiliates as a', 'a.id', '=', 'w.affiliate_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->orderByDesc('w.lifetime_deposited_cents')
            ->get([
                'w.affiliate_id', 'w.balance_cents', 'w.lifetime_deposited_cents',
                'w.lifetime_spent_cents', 'w.updated_at',
                'a.referral_code', 'a.approval_status',
                'u.name as nome', 'u.email as email',
            ])
            ->map(function ($l) {
                $criados = DB::table('affiliate_wallet_transactions')
                    ->where('affiliate_id', $l->affiliate_id)
                    ->where('kind', 'criacao_usuario')->count();

                return [
                    'affiliate_id'      => $l->affiliate_id,
                    'nome'              => $l->nome,
                    'email'             => $l->email,
                    'codigo'            => $l->referral_code,
                    'situacao'          => $l->approval_status,
                    'saldo'             => round($l->balance_cents / 100, 2),
                    'total_depositado'  => round($l->lifetime_deposited_cents / 100, 2),
                    'total_gasto'       => round($l->lifetime_spent_cents / 100, 2),
                    'usuarios_criados'  => $criados,
                    'ultimo_movimento'  => $l->updated_at,
                ];
            });

        return response()->json([
            'carteiras' => $linhas,
            'totais'    => [
                'depositado' => round((int) DB::table('affiliate_wallets')->sum('lifetime_deposited_cents') / 100, 2),
                'gasto'      => round((int) DB::table('affiliate_wallets')->sum('lifetime_spent_cents') / 100, 2),
                'em_saldo'   => round((int) DB::table('affiliate_wallets')->sum('balance_cents') / 100, 2),
                'usuarios_criados' => DB::table('affiliate_wallet_transactions')->where('kind', 'criacao_usuario')->count(),
            ],
        ]);
    }

    /** GET /v1/admin/affiliate-wallets/{id} — extrato completo de um afiliado. */
    public function adminExtrato(Request $r, int $affiliateId): JsonResponse
    {
        $this->exigeAdmin($r);
        $w = $this->carteira->carteira($affiliateId);

        return response()->json([
            'affiliate_id' => $affiliateId,
            'saldo'        => round($w->balance_cents / 100, 2),
            'extrato'      => $this->carteira->extrato($affiliateId, 200),
            'depositos'    => DB::table('affiliate_wallet_deposits')
                ->where('affiliate_id', $affiliateId)->orderByDesc('id')->limit(50)
                ->get(['id', 'amount_cents', 'status', 'created_at', 'paid_at', 'gateway_charge_id'])
                ->map(fn ($d) => [
                    'id'     => $d->id,
                    'valor'  => round($d->amount_cents / 100, 2),
                    'status' => $d->status,
                    'criado' => $d->created_at,
                    'pago'   => $d->paid_at,
                    'charge' => $d->gateway_charge_id,
                ]),
        ]);
    }

    /** POST /v1/admin/affiliate-wallets/deposit/{id}/confirm — PIX caiu e o webhook nao veio. */
    public function adminConfirmarDeposito(Request $r, int $depositoId): JsonResponse
    {
        $this->exigeAdmin($r);
        $ok = $this->carteira->confirmarDeposito($depositoId, (int) $r->user()->id, 'Confirmado na mao pelo admin');

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }

    /** POST /v1/admin/affiliate-wallets/{id}/adjust — ajuste manual, sempre com motivo. */
    public function adminAjustar(Request $r, int $affiliateId): JsonResponse
    {
        $this->exigeAdmin($r);
        $r->validate([
            'valor'   => 'required|numeric|not_in:0',
            'motivo'  => 'required|string|max:255',
        ]);

        $valor   = (float) $r->input('valor');
        $direcao = $valor > 0 ? 'in' : 'out';

        try {
            $saldo = $this->carteira->movimenta(
                $affiliateId, $direcao, (int) round(abs($valor) * 100),
                'ajuste_admin', (string) $r->user()->id, $r->input('motivo')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Saldo insuficiente pra esse ajuste.'], 422);
        }

        return response()->json(['saldo' => round($saldo / 100, 2)]);
    }
}
