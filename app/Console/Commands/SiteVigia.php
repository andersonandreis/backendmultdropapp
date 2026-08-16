<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-VIGIA-DINHEIRO (14/08) — pedido do Ruan: "planejar pra nunca mais acontecer".
 *
 * O QUE ACONTECEU HOJE, e por que um vigia resolve:
 * o tráfego gastou R$47,38 e vendeu ZERO, com CTR de 6,42%. O criativo entregava
 * gente; a página escondia o botão. E não era um defeito só — eram QUATRO, todos
 * da mesma família:
 *   1. o único botão de compra ficava a 950px numa tela de 815px (27% das sessões
 *      nunca rolaram a página, logo nunca viram botão nenhum);
 *   2. a página prometia "a partir de R$29,90" e o botão levava pro plano de R$149;
 *   3. os planos de vídeo, criados em 07/08, nunca foram cadastrados no checkout —
 *      quem ia pagar R$149 lia "Acesso à plataforma / Suporte por chat";
 *   4. 36 vendas reais em 28 dias contra 7 eventos de Compra: o Meta otimizava cego.
 *
 * O QUE LIGA OS QUATRO: ninguém mediu o resultado PELO LADO DE QUEM COMPRA. Testar
 * que "a página carrega" e que "o checkout responde" passa em todos eles.
 *
 * Este comando é o irmão do video:vigia. Aquele mede ENTREGA em vez de processo;
 * este mede se o CAMINHO DO DINHEIRO está inteiro. Escreve em ERROR de propósito —
 * o .env está em LOG_LEVEL=error e info/warning some.
 */
class SiteVigia extends Command
{
    protected $signature   = 'site:vigia {--json}';
    protected $description = 'Vigia o caminho do dinheiro: botão, promessa, checkout e reporte de venda';

    private const SITE = 'https://seller.global';

    public function handle(): int
    {
        $alertas = [];
        $ok      = [];

        // o HTML é uma casca (SPA): o que vale conferir é o pacote de JS servido
        // SEL-VIGIA-VARRE-TUDO (14/08) — armadilha que ja me pegou TRES vezes hoje:
        // ler so o assets/index-*.js. O site e partido em pedacos carregados sob
        // demanda, e as coisas que importam moram fora do principal (o checkout num
        // pedaco, o estudio noutro). Grep no arquivo errado da "nao achei" e a gente
        // conclui que o conserto nao subiu — quando subiu.
        // Como este comando roda NO SERVIDOR, leio o docroot inteiro em vez de baixar
        // por HTTP: mais barato e sem chance de pegar so um pedaco.
        $bundle = '';
        foreach (glob('/home/seller.global/public_html/assets/*.js') ?: [] as $arq) {
            $bundle .= (string) @file_get_contents($arq);
        }
        if ($bundle === '') {   // fallback: servidor sem acesso ao docroot
            $html = @file_get_contents(self::SITE . '/');
            if ($html && preg_match('#assets/index-[A-Za-z0-9_-]+\.js#', $html, $m)) {
                $bundle = (string) @file_get_contents(self::SITE . '/' . $m[0]);
            }
        }
        if ($bundle === '') {
            $alertas[] = 'nao consegui baixar o pacote do site — vigia cego, conferir o deploy';
            $this->reporta($alertas, $ok);

            return self::SUCCESS;
        }

        // ---------------------------------------------------------------- 1) o botão existe?
        // Não dá pra medir a dobra sem navegador, mas dá pra garantir que a página
        // de venda TEM link de compra. Sumir o botão foi metade do problema de hoje.
        if (! str_contains($bundle, '/checkout?plan=')) {
            $alertas[] = 'a pagina de venda NAO tem link de compra no pacote servido';
        } else {
            $ok[] = 'link de compra presente';
        }

        // ------------------------------------------- 2) a promessa bate com o botão?
        if (preg_match('#/checkout\?plan=\$\{?([a-z_]+)#', $bundle, $m) || preg_match('#/checkout\?plan=([a-z_]+)#', $bundle, $m)) {
            $slugDoBotao = $m[1];
            $plano = DB::table('plans')->where('slug', $slugDoBotao)->first();

            if ($plano) {
                $precoDoBotao = (float) $plano->price_monthly;
                // o menor preço ATIVO da família de vídeo é o que a propaganda promete
                $menor = (float) DB::table('plans')->where('is_active', 1)
                    ->where('slug', 'like', 'video_%')->min('price_monthly');

                if ($menor > 0 && $precoDoBotao > $menor * 1.5) {
                    $alertas[] = sprintf(
                        'PROMESSA QUEBRADA: o botao leva pro plano %s (R$ %.2f) e o mais barato anunciado e R$ %.2f',
                        $slugDoBotao, $precoDoBotao, $menor
                    );
                } else {
                    $ok[] = sprintf('botao leva pro plano %s (R$ %.2f), coerente', $slugDoBotao, $precoDoBotao);
                }
            }
        }

        // -------------------------- 3) todo plano vendido tem descricao no checkout?
        // Foi assim que os planos de video ficaram com texto generico por 7 dias.
        $semDescricao = [];
        foreach (DB::table('plans')->where('is_active', 1)->pluck('slug') as $slug) {
            $vendido = str_contains($bundle, "plan=" . $slug) || str_contains($bundle, '"' . $slug . '"');
            if (! $vendido) {
                continue;   // plano que a loja nao oferece: nao me interessa aqui
            }
            // a descricao vive como chave do PLAN_FEATURES; se o slug aparece mas
            // nenhuma frase de beneficio veio junto, ele cai no texto generico
            // a minificacao tira as aspas da chave: sai video_pro:[{ e nao "video_pro":[{
            $curado = (bool) preg_match('#["\']?' . preg_quote($slug, '#') . '["\']?\s*:\s*\[\s*\{#', $bundle);

            // SEL-VIGIA-DESCRICAO-VALE (14/08) — eu mesmo fiz este alerta gritar errado.
            // Depois do SEL-CHECKOUT-SEM-GENERICO, plano sem beneficio chumbado no codigo
            // NAO cai mais no texto generico: ele usa a PROPRIA descricao, vinda do banco.
            // O vigia continuava procurando so a lista chumbada e acusava 6 planos que ja
            // estavam mostrando conteudo de verdade. Alarme falso e o que ensina a ignorar
            // alarme — a pergunta certa e "esse plano tem ALGO pra dizer", das duas fontes.
            $temDescricaoPropria = trim((string) DB::table('plans')->where('slug', $slug)->value('description')) !== '';

            if (! $curado && ! $temDescricaoPropria) {
                $semDescricao[] = $slug;
            }
        }
        if ($semDescricao) {
            $alertas[] = 'plano(s) a venda SEM descricao propria no checkout (cai no texto generico): '
                . implode(', ', $semDescricao);
        } else {
            $ok[] = 'todo plano a venda tem descricao no checkout';
        }

        // --------------------------------------- 4) venda que aconteceu foi reportada?
        $vendas24h = DB::table('subscriptions')
            ->where('status', 'active')
            ->whereNotNull('pagarme_subscription_id')
            ->where('updated_at', '>', now()->subDay())
            ->count();

        $reportadas = 0;
        $log = storage_path('logs/laravel-' . now()->toDateString() . '.log');
        if (is_file($log)) {
            $reportadas = substr_count((string) @file_get_contents($log), 'REPORTADA ao pixel');
        }
        if ($vendas24h > 0 && $reportadas === 0) {
            $alertas[] = "{$vendas24h} venda(s) ativadas em 24h e NENHUMA reportada ao pixel — o Meta esta otimizando cego";
        } elseif ($vendas24h > 0) {
            $ok[] = "{$vendas24h} venda(s) em 24h, {$reportadas} reportada(s)";
        }

        // -------------------------------------------- 5) o nome da marca sai certo?
        foreach (['..global' => 'ponto duplicado no nome', 'tokfyio' => 'nome sem ponto'] as $errado => $oque) {
            if (str_contains($bundle, $errado)) {
                $alertas[] = "marca escrita errada no site ({$oque}): {$errado}";
            }
        }

        // ------------------------------------- 6) sobrou placeholder cru na tela?
        foreach (['[VÍDEO:', 'undefined,', '{{'] as $lixo) {
            if (str_contains($bundle, $lixo) && $lixo === '[VÍDEO:') {
                $alertas[] = "placeholder cru vazando pro cliente: {$lixo}";
            }
        }

        $this->reporta($alertas, $ok);

        return self::SUCCESS;
    }

    private function reporta(array $alertas, array $ok): void
    {
        $resumo = ['quando' => now()->toDateTimeString(), 'alertas' => $alertas, 'ok' => $ok];

        if ($alertas) {
            Log::error('[SEL-VIGIA-DINHEIRO] caminho do dinheiro com problema', $resumo);
        }
        @file_put_contents('/var/log/pulso-dinheiro.log',
            json_encode($resumo, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        if ($this->option('json')) {
            $this->line(json_encode($resumo, JSON_UNESCAPED_UNICODE));

            return;
        }
        foreach ($ok as $o) {
            $this->info('  ok: ' . $o);
        }
        foreach ($alertas as $a) {
            $this->error('  ALERTA: ' . $a);
        }
        if (! $alertas) {
            $this->info('  caminho do dinheiro inteiro');
        }
    }
}
