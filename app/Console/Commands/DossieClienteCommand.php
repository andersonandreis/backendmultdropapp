<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-BLINDAGEM-CHARGEBACK (15/08, pedido do Ruan: "se eu tiver cliente mal
 * intencionado de pagar no Pix, a gente libera o acesso, depois ele fala que não foi
 * ele, que caiu no golpe... quero com um clique toda a documentação, IP, tudo dele
 * ali, confirmação de entrega, tudo").
 *
 * O QUE ISTO É: o dossiê de um cliente, montado a partir do que a plataforma JÁ
 * registra — nada aqui é inventado nem estimado. Serve pra responder disputa de PIX,
 * chargeback e reclamação: mostra QUEM entrou, DE ONDE, QUANDO, o que recebeu por
 * e-mail (e se abriu/clicou), o que pagou e o que foi ENTREGUE.
 *
 * O QUE ELE NÃO FAZ, e é importante estar escrito:
 *   - NÃO tem MAC address. Navegador nenhum entrega MAC — nem o nosso nem o de
 *     ninguém. O equivalente honesto é o FINGERPRINT do aparelho, que a gente
 *     coleta e está aqui. Prometer MAC num documento de disputa seria mentira.
 *   - NÃO inclui gravação de tela por padrão. Existe (14.448 sessões), mas é dado
 *     sensível: entra só se pedido explicitamente e com critério de LGPD.
 *
 * Uso:  php artisan dossie:cliente cliente@email.com
 */
class DossieClienteCommand extends Command
{
    protected $signature = 'dossie:cliente {email : e-mail do cliente}
                            {--json : devolve JSON em vez do relatorio legivel}';

    protected $description = 'Monta o dossie de defesa (chargeback/disputa) de um cliente';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $user = DB::table('users')->where('email', $email)->first();
        if (! $user) {
            $this->error("Nao existe usuario com o e-mail {$email}.");
            return 1;
        }

        $client = DB::table('clients')->where('user_id', $user->id)->first();

        // ── 1. quem é e desde quando ──────────────────────────────────────────
        $cadastro = [
            'user_id'          => $user->id,
            'client_id'        => $client->id ?? null,
            'nome'             => $user->name ?? null,
            'email'            => $user->email,
            'cadastrado_em'    => $user->created_at ?? null,
            'email_confirmado' => $user->email_verified_at ?? null,
        ];

        // ── 2. de onde ele acessou (IP + aparelho), tirado das geracoes ───────
        //     Cada geracao de video grava ip/user_agent/fingerprint. Isso e a prova
        //     mais forte que temos de USO da conta — nao e login, e ACAO.
        $acessos = DB::table('video_generation_reservations')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get(['created_at', 'ip', 'user_agent', 'fingerprint', 'status']);

        $ips = $acessos->pluck('ip')->filter()->unique()->values();
        $aparelhos = $acessos->pluck('fingerprint')->filter()->unique()->values();

        // ── 3. e-mails: enviado, ABERTO, CLICADO ──────────────────────────────
        //     Abertura e clique valem mais que envio numa disputa: provam que a
        //     pessoa recebeu E interagiu.
        $emails = DB::table('email_logs')
            ->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id)->orWhere('to_email', $email);
            })
            ->orderBy('sent_at')
            ->get(['email_type', 'to_email', 'status', 'sent_at', 'opened_at', 'opened_count', 'clicked_at', 'click_count']);

        // ── 4. o que ele pagou ────────────────────────────────────────────────
        $pagamentos = collect();
        if ($client) {
            $pagamentos = DB::table('plan_upgrade_charges')
                ->where('client_id', $client->id)
                ->orderBy('created_at')
                ->get(['created_at', 'status', 'payment_method', 'gateway', 'gateway_order_id', 'diff_amount_cents', 'to_plan_id']);
        }

        // ── 5. o que foi ENTREGUE (o coracao da defesa) ───────────────────────
        $entregues = DB::table('ai_video_pipelines')
            ->where('user_id', $user->id)
            ->where('step', 'done')
            ->whereNotNull('output_url')
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'updated_at', 'output_url']);

        $dossie = [
            'gerado_em'  => now()->toIso8601String(),
            'cadastro'   => $cadastro,
            'acessos'    => [
                'total_geracoes'   => $acessos->count(),
                'ips_distintos'    => $ips->all(),
                'aparelhos'        => $aparelhos->count(),
                'primeiro_uso'     => optional($acessos->first())->created_at,
                'ultimo_uso'       => optional($acessos->last())->created_at,
            ],
            'emails'     => [
                'enviados' => $emails->count(),
                'abertos'  => $emails->where('opened_at', '!=', null)->count(),
                'clicados' => $emails->where('clicked_at', '!=', null)->count(),
                'lista'    => $emails->take(20),
            ],
            'pagamentos' => $pagamentos,
            'entregas'   => [
                'total'    => $entregues->count(),
                'primeira' => optional($entregues->first())->created_at,
                'ultima'   => optional($entregues->last())->created_at,
                'videos'   => $entregues,
            ],
            'ressalvas'  => [
                'MAC address nao e coletavel por navegador — usamos fingerprint do aparelho.',
                'Gravacao de tela existe mas nao entra aqui por padrao (LGPD).',
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($dossie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info("=== DOSSIE DE DEFESA — {$email} ===");
        $this->line("gerado em {$dossie['gerado_em']}");
        $this->newLine();

        $this->line("CADASTRO");
        $this->line("  cliente #{$cadastro['user_id']} · cadastrado em " . ($cadastro['cadastrado_em'] ?? '-'));
        $this->line("  e-mail confirmado: " . ($cadastro['email_confirmado'] ?? 'NAO CONFIRMADO'));
        $this->newLine();

        $this->line("USO DA CONTA (prova de que a pessoa usou o servico)");
        $this->line("  geracoes: {$dossie['acessos']['total_geracoes']} · aparelhos distintos: {$dossie['acessos']['aparelhos']}");
        $this->line("  IPs: " . (count($ips) ? implode(', ', array_slice($ips->all(), 0, 6)) . (count($ips) > 6 ? ' …' : '') : '-'));
        $this->line("  primeiro uso: " . ($dossie['acessos']['primeiro_uso'] ?? '-'));
        $this->line("  ultimo uso:   " . ($dossie['acessos']['ultimo_uso'] ?? '-'));
        $this->newLine();

        $this->line("E-MAILS");
        $this->line("  enviados {$dossie['emails']['enviados']} · abertos {$dossie['emails']['abertos']} · clicados {$dossie['emails']['clicados']}");
        $this->newLine();

        $this->line("PAGAMENTOS");
        if ($pagamentos->isEmpty()) {
            $this->line('  (nenhuma cobranca registrada nesta tabela)');
        } else {
            foreach ($pagamentos as $p) {
                $this->line("  {$p->created_at} · {$p->status} · {$p->payment_method}/{$p->gateway} · R$ "
                    . number_format(((int) $p->diff_amount_cents) / 100, 2, ',', '.')
                    . " · pedido {$p->gateway_order_id}");
            }
        }
        $this->newLine();

        $this->line("ENTREGAS (o que ele recebeu)");
        $this->line("  videos entregues: {$dossie['entregas']['total']}");
        $this->line("  do primeiro em " . ($dossie['entregas']['primeira'] ?? '-') . " ao ultimo em " . ($dossie['entregas']['ultima'] ?? '-'));
        foreach ($entregues->take(5) as $v) {
            $this->line("    #{$v->id} {$v->created_at} {$v->output_url}");
        }
        if ($entregues->count() > 5) {
            $this->line('    … e mais ' . ($entregues->count() - 5) . ' video(s)');
        }
        $this->newLine();

        $this->warn('RESSALVAS (nao omitir em disputa):');
        foreach ($dossie['ressalvas'] as $r) { $this->line("  - {$r}"); }

        return 0;
    }
}
