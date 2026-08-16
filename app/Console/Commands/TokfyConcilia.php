<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * TOK-CONCILIA (15/08) — venda paga na Tokfy vira acesso sozinha, sem depender do webhook.
 *
 * O PROBLEMA: o webhook da conta ANTIGA do Pagar.me (Tudo On Line, onde o dinheiro da
 * Tokfy cai) aponta pra um Supabase que NAO e nosso
 * (omvstizxjosygkcolzzl.supabase.co/functions/v1/pagarme-webhook). Resultado medido em
 * 15/08: 35 clientes pagaram R$ 7.695 entre 11 e 15/08 e NENHUM tinha plano. Descobri no
 * grito, dias depois.
 *
 * POR QUE NAO ARRUMO O WEBHOOK: aquela conta e COMPARTILHADA com Fornecefy, MultDrop,
 * JTDrop e MEStoreDrop. Reapontar o webhook dela mexe no fluxo dos quatro. Nao encosto
 * em config de terceiro pra resolver problema meu.
 *
 * O QUE ESTE COMANDO FAZ: pergunta ao Pagar.me quem pagou (leitura pura, nao escreve nada
 * la), e libera o acesso do nosso lado. Se o webhook um dia voltar a funcionar, este
 * comando simplesmente nao acha nada pra fazer — os dois convivem sem brigar.
 *
 * IDEMPOTENTE de verdade: a marca e o id do pedido no Pagar.me, gravado em
 * subscriptions.external_payment_id. Rodar dez vezes seguidas nao cria dez assinaturas
 * nem manda dez e-mails.
 *
 *   php artisan tokfy:concilia --dry      -> so mostra o que faria
 *   php artisan tokfy:concilia            -> libera e avisa o cliente
 *   php artisan tokfy:concilia --sem-email -> libera e NAO manda e-mail (uso em teste)
 */
class TokfyConcilia extends Command
{
    protected $signature = 'tokfy:concilia
        {--dias=7 : olha pagamentos dos ultimos N dias}
        {--dry : nao escreve nada, so relata}
        {--sem-email : cria o acesso mas nao envia e-mail}';

    protected $description = 'Libera acesso Tokfy de quem pagou, sem depender do webhook da conta antiga.';

    /** Planos reais (o Ruan corrigiu em 15/08: NAO existe anual — e mensal, 149 e 297). */
    private const PLANO_ULTRA     = 101;   // R$297
    private const PLANO_ILIMITADO = 100;   // R$149

    private const LOG = '/home/api.seller.global/public_html/storage/logs/tokfy-concilia.log';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        // ══ DUAS CONTAS (16/08) ═══════════════════════════════════════════════════════
        // O checkout da Tokfy mudou hoje para a conta NOVA (ON LINE RJ). Quem so olhasse a
        // conta antiga deixaria TODA venda nova sem acesso — exatamente o prejuizo de
        // 11-15/08 (35 clientes, R$ 7.695 pagos sem plano), invertido.
        // A antiga continua valendo porque ainda tem cobranca velha caindo la.
        // config() e nao env(): com config em cache o .env nao e lido e este comando
        // morria em TODA execucao (foi o que aconteceu hoje, das 15:49 em diante).
        $contas = array_filter([
            'antiga' => (string) config('services.pagarme_tokfy.legado', ''),
            'nova'   => (string) config('services.pagarme_tokfy.nova', ''),
        ]);

        if (! $contas) {
            $this->erro('Nenhuma chave no .env (PAGARME_TOKFY_LEGADO / PAGARME_TOKFY_NOVA) — sem ela nao da pra perguntar quem pagou.');

            return self::FAILURE;
        }

        $desde = now()->subDays((int) $this->option('dias'));

        $pedidos    = [];
        $vistos     = [];
        $respondeu  = false;

        foreach ($contas as $apelido => $chave) {
            $lote = $this->pedidosPagos($chave, $desde);

            if ($lote === null) {
                // Uma conta fora do ar nao pode impedir a outra de liberar acesso.
                $this->erro("Pagar.me nao respondeu na conta {$apelido} — sigo com as demais.");
                continue;
            }

            $respondeu = true;

            foreach ($lote as $p) {
                if (isset($vistos[$p['id']])) { continue; }   // mesma cobranca nas duas chaves
                $vistos[$p['id']] = true;
                $p['conta'] = $apelido;
                $pedidos[]  = $p;
            }

            $this->linha("  conta {$apelido}: " . count($lote) . ' pagamento(s) Tokfy');
        }

        if (! $respondeu) {
            $this->erro('Pagar.me nao respondeu em nenhuma conta — nao mexo em nada e tento de novo no proximo ciclo.');

            return self::FAILURE;
        }

        $this->linha('==== ciclo' . ($dry ? ' [DRY]' : '') . ' — ' . count($pedidos) . ' pagamento(s) Tokfy desde ' . $desde->toDateString());

        $liberados = 0; $jaTinham = 0; $falhas = 0;

        foreach ($pedidos as $p) {
            try {
                // Ja liberado? A marca e o id do pedido — nao o e-mail, porque a mesma
                // pessoa pode comprar duas vezes e as duas tem que valer.
                if (DB::table('subscriptions')->where('external_payment_id', $p['id'])->exists()) {
                    $jaTinham++;
                    continue;
                }

                if ($dry) {
                    $this->linha("[FARIA] {$p['id']} {$p['email']} R\${$p['valor']} -> " . $this->nomeDoPlano($p['valor']));
                    $liberados++;
                    continue;
                }

                $r = $this->liberar($p);
                $liberados++;
                $this->linha("[LIBERADO] {$p['id']} {$p['email']} R\${$p['valor']} -> {$r['plano']} (user={$r['user_id']} client={$r['client_id']}, conta " . ($r['nova'] ? 'NOVA' : 'ja existia') . ')');

                if (! $this->option('sem-email') && empty($r['so_carimbou'])) {
                    $enviou = $this->avisarCliente($r['user'], $p, $r['nova'], $r['senha']);
                    $this->linha('   e-mail: ' . ($enviou ? 'enviado' : 'FALHOU (o acesso ja esta liberado; reenvio no proximo ciclo)'));
                }
            } catch (\Throwable $e) {
                $falhas++;
                $this->linha("[ERRO] {$p['id']} {$p['email']}: " . mb_substr($e->getMessage(), 0, 120));
            }
        }

        $this->linha("==== fim | liberados={$liberados} ja_tinham={$jaTinham} falhas={$falhas}");
        $this->info("tokfy:concilia | liberados={$liberados} ja_tinham={$jaTinham} falhas={$falhas}");

        return self::SUCCESS;
    }

    /** Le os pedidos PAGOS da conta antiga. Leitura pura — nao escreve nada no Pagar.me. */
    private function pedidosPagos(string $chave, \DateTimeInterface $desde): ?array
    {
        $achados = [];

        for ($pagina = 1; $pagina <= 10; $pagina++) {
            try {
                $resp = Http::withBasicAuth($chave, '')
                    ->timeout(30)
                    ->get('https://api.pagar.me/core/v5/orders', ['status' => 'paid', 'size' => 100, 'page' => $pagina]);
            } catch (\Throwable $e) {
                return null;
            }

            if (! $resp->successful()) { return $pagina === 1 ? null : $achados; }

            $dados = $resp->json('data') ?: [];
            if (! $dados) { break; }

            $passouDoPrazo = false;

            foreach ($dados as $o) {
                $criado = strtotime($o['created_at'] ?? '');
                if ($criado && $criado < $desde->getTimestamp()) { $passouDoPrazo = true; continue; }

                // So compra da TOKFY. A conta e compartilhada: HubAI Start/Scaling e de
                // outro produto e nao pode virar acesso Tokfy.
                $ehTokfy = false;
                $valor = 0;
                foreach (($o['items'] ?? []) as $i) {
                    if (stripos((string) ($i['description'] ?? ''), 'tokfy') !== false) { $ehTokfy = true; }
                    $valor += ((int) ($i['amount'] ?? 0)) * ((int) ($i['quantity'] ?? 1));
                }
                if (! $ehTokfy) { continue; }

                $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
                if ($email === '') { continue; }

                $achados[] = [
                    'id'    => $o['id'],
                    'email' => $email,
                    'nome'  => trim((string) ($o['customer']['name'] ?? '')) ?: 'Cliente Tokfy',
                    'valor' => round($valor / 100, 2),
                    'data'  => substr((string) ($o['created_at'] ?? ''), 0, 10),
                ];
            }

            if ($passouDoPrazo || count($dados) < 100) { break; }
        }

        return $achados;
    }

    private function nomeDoPlano(float $valor): string
    {
        return $valor >= 200 ? 'Vídeo IA — Ultra' : 'Vídeo IA — Ilimitado';
    }

    /**
     * Libera o acesso. users -> clients -> subscriptions(client_id = clients.id).
     * Esse encadeamento e o que eu errei hoje de manha achando que client_id era users.id:
     * escrevi na assinatura de OUTRA pessoa. Aqui o client_id vem sempre da tabela clients.
     */
    private function liberar(array $p): array
    {
        $planoId = $p['valor'] >= 200 ? self::PLANO_ULTRA : self::PLANO_ILIMITADO;

        return DB::transaction(function () use ($p, $planoId) {
            $user = \App\Models\User::whereRaw('LOWER(email) = ?', [$p['email']])->first();
            $nova = false;
            $senha = null;

            if (! $user) {
                $nova  = true;
                $senha = Str::password(10, true, true, false);
                $user  = \App\Models\User::create([
                    'name'              => $p['nome'],
                    'email'             => $p['email'],
                    'password'          => bcrypt($senha),
                    'role'              => 'client',
                    'email_verified_at' => now(),
                ]);
            }

            $clientId = DB::table('clients')->where('user_id', $user->id)->value('id');
            if (! $clientId) {
                $clientId = DB::table('clients')->insertGetId([
                    'user_id' => $user->id, 'is_active' => 1, 'marca' => 'tokfy',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $sub = DB::table('subscriptions')->where('client_id', $clientId)->orderByDesc('id')->first();

            // TOK-CONCILIA-NAO-PISA-EM-QUEM-JA-TEM: quem ja esta ATIVO nao se mexe.
            // So carimbo o id do pagamento pra nao reprocessar pra sempre — sem trocar
            // plano, sem remexer data, sem e-mail. Mexer aqui seria reescrever a
            // assinatura de quem esta usando a plataforma agora.
            if ($sub && $sub->status === 'active') {
                DB::table('subscriptions')->where('id', $sub->id)
                    ->update(['external_payment_id' => $p['id'], 'updated_at' => now()]);

                return [
                    'user' => $user, 'user_id' => $user->id, 'client_id' => $clientId,
                    'plano' => 'ja tinha acesso — so carimbei o pagamento',
                    'nova' => false, 'senha' => null, 'so_carimbou' => true,
                ];
            }

            $campos = [
                'plan_id'              => $planoId,
                'status'               => 'active',
                'marca'                => 'tokfy',
                'payment_method'       => 'pix',
                'external_payment_id'  => $p['id'],
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
                'updated_at'           => now(),
            ];

            if ($sub) {
                DB::table('subscriptions')->where('id', $sub->id)->update($campos);
            } else {
                DB::table('subscriptions')->insert($campos + ['client_id' => $clientId, 'created_at' => now()]);
            }

            return [
                'user' => $user, 'user_id' => $user->id, 'client_id' => $clientId,
                'plano' => $this->nomeDoPlano($p['valor']), 'nova' => $nova, 'senha' => $senha,
            ];
        });
    }

    /**
     * E-mail com a cara da TOKFY (remetente suporte@tokfy.io). Ele comprou Tokfy: aviso
     * chegando como seller.global vira "nao reconheco essa cobranca", que e o comeco de
     * um chargeback.
     *
     * O link e /reset-password?token=&email= com token do Password::broker — foi assim
     * que eu descobri, no dia 15, que o /esqueci-senha que eu tinha mandado pros 30 NAO
     * EXISTE: como o site e SPA, a rota inexistente mostra a pagina de vendas e a pessoa
     * acha que e propaganda.
     */
    private function avisarCliente(\App\Models\User $user, array $p, bool $nova, ?string $senha): bool
    {
        try {
            $acesso = $nova
                ? '<p style="margin:0 0 6px"><b>Seu login:</b> ' . e($user->email) . '</p>'
                  . '<p style="margin:0"><b>Sua senha:</b> <span style="font-family:monospace;font-size:16px;background:#f3f3f3;padding:4px 8px;border-radius:6px">'
                  . e($senha) . '</span></p>'
                : '<p style="margin:0 0 6px"><b>Seu login:</b> ' . e($user->email) . '</p>'
                  . '<p style="margin:0;color:#555">Clique no botão abaixo para criar a sua senha e entrar.</p>';

            $link = 'https://tokfy.io/reset-password?token=' . Password::broker()->createToken($user)
                  . '&email=' . urlencode($user->email);

            $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111">'
                . '<h1 style="font-size:22px;margin:0 0 4px">Seu acesso ao Tokfy está liberado</h1>'
                . '<p style="color:#555;margin:0 0 18px">Olá, ' . e($p['nome']) . '!</p>'
                . '<p style="margin:0 0 18px">Confirmamos o seu pagamento de <b>R$ '
                . number_format((float) $p['valor'], 2, ',', '.') . '</b> e liberamos o plano <b>'
                . e($this->nomeDoPlano($p['valor'])) . '</b> na sua conta.</p>'
                . '<div style="border:1px solid #e5e5e5;border-radius:12px;padding:16px;margin:0 0 18px">' . $acesso . '</div>'
                . '<p style="margin:0 0 22px"><a href="' . $link . '" style="display:inline-block;background:#111;color:#fff;'
                . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700">'
                . ($nova ? 'Entrar no Tokfy' : 'Criar minha senha e entrar') . '</a></p>'
                . '<p style="color:#666;font-size:13px;margin:0">Se o botão não abrir, copie este endereço:<br>'
                . '<span style="word-break:break-all">' . $link . '</span></p>'
                . '<p style="color:#999;font-size:12px;margin:16px 0 0">Tokfy · suporte@tokfy.io</p>'
                . '</div>';

            Mail::html($html, fn ($m) => $m->to($user->email)->subject('Seu acesso ao Tokfy está liberado'));

            return true;
        } catch (\Throwable $e) {
            Log::error('[TOK-CONCILIA] e-mail falhou', ['para' => $user->email, 'err' => mb_substr($e->getMessage(), 0, 140)]);

            return false;
        }
    }

    private function linha(string $t): void
    {
        @file_put_contents(self::LOG, '[' . now()->toDateTimeString() . '] ' . $t . "\n", FILE_APPEND);
        $this->line($t);
    }

    private function erro(string $t): void
    {
        $this->linha('[ERRO] ' . $t);
        $this->error($t);
    }
}
