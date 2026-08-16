<?php

namespace App\Services\Affiliate;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-CARTEIRA-AFILIADO (14/08) — a carteira do afiliado, pedida pelo Ruan na live:
 *
 *   "faz uma carteira pra eles, com QR code, eles botam saldo, e com esse saldo eles
 *    criam os usuarios na plataforma. Eles pagam METADE do plano, porque a comissao
 *    deles e 50% — eu ganho metade da PRIMEIRA mensalidade so. E eu quero controle do
 *    meu lado: quem pagou quanto, quanto tem de saldo, quanto saiu."
 *
 * REGRA DE PRECO: metade do price_monthly lido da tabela `plans` NA HORA. Nao ha
 * numero fixo aqui dentro — se o Ruan mudar o preco do plano, a carteira acompanha
 * sozinha, sem deploy. Hoje:
 *     Video IA - Start      R$  29,90  ->  R$  14,95
 *     Video IA - Ilimitado  R$ 149,00  ->  R$  74,50
 *     Video IA - Ultra      R$ 297,00  ->  R$ 148,50
 *
 * DINHEIRO SEMPRE EM CENTAVOS (int). Float perde centavo em divisao.
 */
class AffiliateWalletService
{
    private const PAGARME = 'https://api.pagar.me/core/v5';

    /** Planos que o afiliado pode vender pela carteira. */
    public const PLANOS_VENDAVEIS = ['video_start', 'video_pro', 'video_ultra'];

    public const DEPOSITO_MINIMO_CENTS  = 1000;    // R$ 10,00
    public const DEPOSITO_MAXIMO_CENTS  = 5000000; // R$ 50.000,00

    /** Marca no `payment_method` pra saber que veio da carteira, nao do cartao. */
    public const PAGO_PELA_CARTEIRA = 'carteira_afiliado';

    /** Garante a linha da carteira e devolve o estado de agora. */
    public function carteira(int $affiliateId): object
    {
        DB::table('affiliate_wallets')->insertOrIgnore([
            'affiliate_id' => $affiliateId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return DB::table('affiliate_wallets')->where('affiliate_id', $affiliateId)->first();
    }

    /** Quanto custa PRA O AFILIADO criar um usuario nesse plano: metade do preco. */
    public function custoDoPlanoCents(string $slug): ?int
    {
        if (! in_array($slug, self::PLANOS_VENDAVEIS, true)) {
            return null;
        }

        $plano = DB::table('plans')->where('slug', $slug)->where('is_active', 1)->first();
        if (! $plano || ! isset($plano->price_monthly)) {
            return null;
        }

        $cheioCents = (int) round(((float) $plano->price_monthly) * 100);

        // SEL-ARREDONDA-REAL (14/08, ordem do Ruan: "arredonda 15") — a metade exata
        // do Start daria R$ 14,95 e ele quer R$ 15. Regra: metade arredondada PRA CIMA
        // no REAL inteiro, nunca no centavo. Fica limpo de falar na live e a casa nunca
        // perde troco:
        //     R$  29,90 -> R$  15,00     R$ 149,00 -> R$  75,00
        //     R$ 297,00 -> R$ 149,00
        return ((int) ceil($cheioCents / 2 / 100)) * 100;
    }

    /** A vitrine do afiliado: preco do cliente x o que ele paga. */
    public function tabelaDePrecos(): array
    {
        $out = [];
        foreach (self::PLANOS_VENDAVEIS as $slug) {
            $plano = DB::table('plans')->where('slug', $slug)->where('is_active', 1)->first();
            if (! $plano) {
                continue;
            }
            $custo = $this->custoDoPlanoCents($slug);
            $out[] = [
                'slug'            => $slug,
                'nome'            => $plano->name,
                'preco_cliente'   => (float) $plano->price_monthly,
                'voce_paga'       => round($custo / 100, 2),
                'voce_paga_cents' => $custo,
                'videos_por_mes'  => $plano->ai_monthly_video_limit ?? null,
                'descricao'       => $plano->description ?? null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ deposito

    /**
     * Cria a cobranca PIX no Pagar.me e guarda o QR. NAO credita nada aqui:
     * saldo so entra quando o pagamento confirma.
     */
    public function gerarDeposito(int $affiliateId, int $valorCents, array $dadosCliente): array
    {
        if ($valorCents < self::DEPOSITO_MINIMO_CENTS) {
            throw new \InvalidArgumentException('deposito_minimo');
        }
        if ($valorCents > self::DEPOSITO_MAXIMO_CENTS) {
            throw new \InvalidArgumentException('deposito_maximo');
        }

        $chave = (string) config('payment.pagarme.api_key', '');
        if ($chave === '') {
            throw new \RuntimeException('pagarme_sem_chave');
        }

        // SEL-PIX-TELEFONE (14/08) — o Pagar.me RECUSA a ordem sem telefone:
        //   {"code":"400","errors":[{"message":"At least one customer phone is required."}]}
        // e o pior e que ele devolve HTTP 200 com status "failed" dentro do corpo, entao
        // sem essa checagem o deposito nascia "pending" com QR VAZIO e o afiliado ficava
        // olhando pra uma tela sem codigo. Peguei no primeiro teste real.
        if (empty($dadosCliente['phones'])) {
            $dadosCliente['phones'] = ['mobile_phone' => $this->telefonePagarme($dadosCliente['_telefone'] ?? null)];
        }
        unset($dadosCliente['_telefone']);

        $payload = [
            'items' => [[
                'amount'      => $valorCents,
                'description' => 'Credito na carteira de afiliado',
                'quantity'    => 1,
                'code'        => 'carteira-afiliado',
            ]],
            'customer' => $dadosCliente,
            'payments' => [['payment_method' => 'pix', 'pix' => ['expires_in' => 3600]]],
            'metadata' => ['tipo' => 'carteira_afiliado', 'affiliate_id' => (string) $affiliateId],
        ];

        $resp = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($chave . ':'),
        ])->timeout(25)->post(self::PAGARME . '/orders', $payload);

        $data = $resp->json();
        if (! $resp->successful()) {
            Log::error('[SEL-CARTEIRA] Pagar.me recusou o PIX', [
                'affiliate_id' => $affiliateId,
                'http'         => $resp->status(),
                'erro'         => $data['message'] ?? null,
            ]);
            throw new \RuntimeException('pix_recusado: ' . ($data['message'] ?? 'gateway nao aceitou'));
        }

        $charge = $data['charges'][0] ?? [];
        $tx     = $charge['last_transaction'] ?? [];

        // SEL-PIX-TELEFONE: o Pagar.me responde HTTP 200 mesmo quando a ordem FALHA —
        // o motivo vem em charges[0].last_transaction.gateway_response.errors. Sem QR
        // nao ha deposito nenhum: falha aqui, alto, em vez de gravar linha morta.
        if (empty($tx['qr_code'])) {
            $motivo = $tx['gateway_response']['errors'][0]['message'] ?? ($data['status'] ?? 'sem qr_code');
            Log::error('[SEL-CARTEIRA] Pagar.me aceitou a chamada mas nao gerou QR', [
                'affiliate_id' => $affiliateId,
                'order_status' => $data['status'] ?? null,
                'motivo'       => $motivo,
            ]);
            throw new \RuntimeException('pix_sem_qr: ' . $motivo);
        }

        $id = DB::table('affiliate_wallet_deposits')->insertGetId([
            'affiliate_id'      => $affiliateId,
            'amount_cents'      => $valorCents,
            'gateway'           => 'pagarme',
            'gateway_order_id'  => $data['id'] ?? null,
            'gateway_charge_id' => $charge['id'] ?? null,
            'qr_code'           => $tx['qr_code'] ?? null,
            'qr_code_url'       => $tx['qr_code_url'] ?? null,
            'status'            => 'pending',
            'expires_at'        => now()->addHour(),
            'gateway_response'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return [
            'deposito_id'  => $id,
            'valor'        => round($valorCents / 100, 2),
            'copia_e_cola' => $tx['qr_code'] ?? null,
            'qr_code_url'  => $tx['qr_code_url'] ?? null,
            'expira_em'    => now()->addHour()->toIso8601String(),
            'status'       => 'pending',
        ];
    }

    /**
     * SEL-PIX-TELEFONE — telefone no formato que o Pagar.me exige (DDI/DDD/numero).
     * Sem isso a ordem volta "failed" com QR vazio. Se o afiliado nao tem telefone
     * no cadastro, usa o do suporte: o campo e obrigatorio pro gateway, nao pra nos.
     */
    private function telefonePagarme(?string $bruto): array
    {
        $so = preg_replace('/\D/', '', (string) $bruto);
        if (strlen($so) >= 12 && str_starts_with($so, '55')) {
            $so = substr($so, 2);   // ja veio com DDI
        }
        if (strlen($so) < 10) {
            $so = '21966395555';    // telefone do suporte, ja usado no atendimento
        }

        return [
            'country_code' => '55',
            'area_code'    => substr($so, 0, 2),
            'number'       => substr($so, 2),
        ];
    }

    /**
     * Confirma e credita. IDEMPOTENTE de proposito: o webhook do Pagar.me repete, e
     * creditar duas vezes o mesmo PIX seria dinheiro inventado.
     */
    public function confirmarDeposito(int $depositoId, ?int $confirmadoPorUserId = null, ?string $nota = null): bool
    {
        return DB::transaction(function () use ($depositoId, $confirmadoPorUserId, $nota) {
            $dep = DB::table('affiliate_wallet_deposits')->where('id', $depositoId)->lockForUpdate()->first();
            if (! $dep) {
                return false;
            }
            if ($dep->status === 'paid') {
                return true; // webhook repetido — nao credita de novo
            }

            DB::table('affiliate_wallet_deposits')->where('id', $depositoId)->update([
                'status'               => 'paid',
                'paid_at'              => now(),
                'confirmed_by_user_id' => $confirmadoPorUserId,
                'updated_at'           => now(),
            ]);

            $this->movimenta((int) $dep->affiliate_id, 'in', (int) $dep->amount_cents,
                'deposito_pix', (string) $depositoId, $nota ?? 'PIX confirmado');

            Log::error('[SEL-CARTEIRA] deposito creditado', [
                'deposito_id'  => $depositoId,
                'affiliate_id' => $dep->affiliate_id,
                'valor'        => $dep->amount_cents / 100,
                'manual'       => $confirmadoPorUserId !== null,
            ]);

            return true;
        });
    }

    /** Acha o deposito pelo id do Pagar.me (usado pelo webhook). */
    public function acharDepositoPorCharge(string $chargeOuOrderId): ?object
    {
        return DB::table('affiliate_wallet_deposits')
            ->where('gateway_charge_id', $chargeOuOrderId)
            ->orWhere('gateway_order_id', $chargeOuOrderId)
            ->first();
    }

    // ------------------------------------------------------------------- extrato

    /**
     * O UNICO lugar que mexe em saldo. Trava a linha, escreve o extrato com o
     * saldo_depois, e nunca deixa o saldo ficar negativo.
     */
    public function movimenta(int $affiliateId, string $direcao, int $valorCents, string $tipo, ?string $ref = null, ?string $nota = null): int
    {
        if ($valorCents <= 0) {
            throw new \InvalidArgumentException('valor_invalido');
        }

        return DB::transaction(function () use ($affiliateId, $direcao, $valorCents, $tipo, $ref, $nota) {
            $this->carteira($affiliateId);

            $w     = DB::table('affiliate_wallets')->where('affiliate_id', $affiliateId)->lockForUpdate()->first();
            $saldo = (int) $w->balance_cents;

            if ($direcao === 'out') {
                if ($saldo < $valorCents) {
                    throw new \RuntimeException('saldo_insuficiente');
                }
                $novo = $saldo - $valorCents;
                DB::table('affiliate_wallets')->where('affiliate_id', $affiliateId)->update([
                    'balance_cents'        => $novo,
                    'lifetime_spent_cents' => (int) $w->lifetime_spent_cents + $valorCents,
                    'updated_at'           => now(),
                ]);
            } else {
                $novo = $saldo + $valorCents;
                DB::table('affiliate_wallets')->where('affiliate_id', $affiliateId)->update([
                    'balance_cents'            => $novo,
                    'lifetime_deposited_cents' => (int) $w->lifetime_deposited_cents + $valorCents,
                    'updated_at'               => now(),
                ]);
            }

            DB::table('affiliate_wallet_transactions')->insert([
                'affiliate_id'        => $affiliateId,
                'direction'           => $direcao,
                'amount_cents'        => $valorCents,
                'balance_after_cents' => $novo,
                'kind'                => $tipo,
                'ref'                 => $ref,
                'note'                => $nota,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            return $novo;
        });
    }

    // ------------------------------------------------------ criar usuario pagando

    /**
     * Cria o cliente na plataforma debitando metade do plano da carteira.
     *
     * Usa o MESMO caminho do cadastro normal (User::create -> UserObserver cria o
     * Client), pra nao nascer usuario meio-feito que nao consegue logar. Se qualquer
     * passo falhar, a transacao inteira volta: nunca fica usuario sem debito nem
     * debito sem usuario.
     */
    public function criarUsuario(int $affiliateId, string $planoSlug, string $nome, string $email, string $senha, ?string $telefone = null, ?string $documento = null): array
    {
        $custo = $this->custoDoPlanoCents($planoSlug);
        if ($custo === null) {
            throw new \InvalidArgumentException('plano_invalido');
        }

        $email = mb_strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw new \RuntimeException('email_ja_existe');
        }

        return DB::transaction(function () use ($affiliateId, $planoSlug, $nome, $email, $senha, $telefone, $documento, $custo) {
            // 1) debita ANTES de criar — sem saldo, nem comeca
            $saldoDepois = $this->movimenta($affiliateId, 'out', $custo, 'criacao_usuario', null,
                "Criou {$email} no plano {$planoSlug}");

            // 2) usuario pelo caminho oficial (o Observer cria o Client junto)
            $user = User::create([
                'name'      => $nome,
                'email'     => $email,
                'password'  => $senha,   // cast 'hashed' no modelo
                'role'      => 'client',
                'is_active' => true,
            ]);

            $client = DB::table('clients')->where('user_id', $user->id)->first();
            if (! $client) {
                throw new \RuntimeException('client_nao_criado');
            }
            DB::table('clients')->where('id', $client->id)->update(array_filter([
                'phone'      => $telefone,
                'document'   => $documento ?? '',
                'is_active'  => 1,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

            // 3) assinatura ativa do plano — marcada como paga PELA CARTEIRA
            $plano = DB::table('plans')->where('slug', $planoSlug)->first();
            DB::table('subscriptions')->insert([
                'client_id'             => $client->id,
                'plan_id'               => $plano->id,
                'status'                => 'active',
                'payment_method'        => self::PAGO_PELA_CARTEIRA,
                'marca'                 => 'sellerglobal',
                'current_period_start'  => now(),
                'current_period_end'    => now()->addMonth(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            // cota de video (tabela paralela que o contador de video le)
            if (DB::getSchemaBuilder()->hasTable('user_video_subscriptions')) {
                DB::table('user_video_subscriptions')->insert([
                    'user_id'                 => $user->id,
                    'plan_slug'               => $planoSlug,
                    'status'                  => 'active',
                    'cycle_started_at'        => now(),
                    'cycle_ends_at'           => now()->addMonth(),
                    'videos_used_this_cycle'  => 0,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }

            // 4) amarra no afiliado — e daqui que sai a comissao dele
            DB::table('affiliate_referrals')->insert([
                'affiliate_id'       => $affiliateId,
                'referred_client_id' => $client->id,
                'referral_code'      => DB::table('affiliates')->where('id', $affiliateId)->value('referral_code'),
                'landing_url'        => 'carteira-afiliado',
                'utm_source'         => 'carteira',
                'attributed_at'      => now(),
                'converted_at'       => now(),
                'status'             => 'converted',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // 5) carimba o ref no extrato agora que o usuario existe
            DB::table('affiliate_wallet_transactions')
                ->where('affiliate_id', $affiliateId)
                ->where('kind', 'criacao_usuario')
                ->whereNull('ref')
                ->orderByDesc('id')->limit(1)
                ->update(['ref' => (string) $user->id]);

            Log::error('[SEL-CARTEIRA] afiliado criou usuario', [
                'affiliate_id' => $affiliateId,
                'user_id'      => $user->id,
                'client_id'    => $client->id,
                'plano'        => $planoSlug,
                'custo'        => $custo / 100,
                'saldo_depois' => $saldoDepois / 100,
            ]);

            return [
                'user_id'      => $user->id,
                'client_id'    => $client->id,
                'nome'         => $nome,
                'email'        => $email,
                'plano'        => $planoSlug,
                'custo'        => round($custo / 100, 2),
                'saldo_depois' => round($saldoDepois / 100, 2),
            ];
        });
    }

    /** Extrato paginado, do mais novo pro mais velho. */
    public function extrato(int $affiliateId, int $limite = 50): array
    {
        return DB::table('affiliate_wallet_transactions')
            ->where('affiliate_id', $affiliateId)
            ->orderByDesc('id')->limit($limite)
            ->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'quando'       => $t->created_at,
                'direcao'      => $t->direction,
                'tipo'         => $t->kind,
                'valor'        => round($t->amount_cents / 100, 2),
                'saldo_depois' => round($t->balance_after_cents / 100, 2),
                'ref'          => $t->ref,
                'nota'         => $t->note,
            ])->all();
    }
}
