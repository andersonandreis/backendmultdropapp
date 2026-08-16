<?php

namespace App\Console\Commands;

use App\Models\AiEngine;
use App\Services\Ai\AiEnginePool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-LEASE (12/08) — "nenhum job pode ser despachado pra um motor morto, e
 * nenhum motor pode ficar preso depois que o job morre".
 *
 * Este comando e a metade que roda SOZINHA (a outra metade vive no AiEnginePool,
 * no caminho da reserva). Ele faz duas coisas, nessa ordem, e nada mais:
 *
 * 1. ZELADOR (local, instantaneo). Varre as travas do pool e devolve as ORFAS --
 *    trava existe, mas o dono parou de bater. Antes disso, a unica forma de uma
 *    trava vazada sumir era o TTL de 600s vencer. Medido em ai_engine_usage do
 *    dia 12/08 (UTC): 36 reservas nunca devolvidas contra 98 geracoes concluidas,
 *    cada uma segurando um motor bom por ate 10 minutos E contando pra quota do
 *    dia. Roda PRIMEIRO de proposito: nao depende de rede, entao mesmo com o PC
 *    fora do ar o motor volta pro pool.
 *
 * 2. SONDA DE SESSAO (SSH ate o PC). Pergunta ao proprio Google, dentro de cada
 *    perfil de Chrome, se a conta ainda esta logada:
 *      https://labs.google/fx/api/auth/session
 *    devolve {"user":{"email":...},"expires":...} quando ha sessao e {} quando
 *    nao ha. E o teste definitivo: nao depende de idioma, de layout, nem de
 *    achar campo de senha na tela. Medido hoje: 6 contas vivas de 11 -- e as
 *    outras 5 continuavam elegiveis pra receber job. Job em conta morta nao
 *    falha rapido: ele PENDURA ate o teto de 420-720s segurando o motor.
 *
 *    O veredito e carimbado em `ai_engines.config_json` (sessao_valida,
 *    sessao_email, sessao_expira, sessao_vista_em, sessao_motivo). Quem le e o
 *    AiEnginePool::estadoDaFrota(), que PULA o motor -- sem desativar, sem
 *    marcar precisa_reconectar (isso e decisao do frota-sync / do Ruan), so
 *    pulando, com o motivo registrado.
 *
 * REGRAS QUE ESTE COMANDO RESPEITA (todas por causa de estrago ja medido aqui):
 *   - NAO abre aba em motor que esta RENDERIZANDO. Se a trava do pool esta
 *     tomada, o perfil e pulado na sonda: cliente gerando vale mais que leitura
 *     fresca. A leitura velha simplesmente expira e o pool volta a fail-open.
 *   - NAO escreve NADA no PC. Manda o script pelo STDIN do `node` (o proprio
 *     node executa stdin quando nao ha arquivo). Nenhum arquivo criado ou
 *     alterado em C:\sellerglobal-render.
 *   - NAO tenta logar em lugar nenhum. So le e registra.
 *   - Fail-open em tudo: SSH fora, JSON quebrado ou PC desligado NAO tiram motor
 *     nenhum do pool.
 */
class VideoLeaseGuardiao extends Command
{
    protected $signature = 'video:lease-guardiao
                            {--so-zelar : so devolve travas orfas, nao sonda sessao}
                            {--so-sondar : so sonda sessao, nao mexe em trava}
                            {--timeout=90 : teto em segundos pro SSH da sonda}';

    protected $description = 'SEL-LEASE: devolve travas orfas do pool de video e sonda a sessao Google de cada perfil do PC.';

    public function handle(): int
    {
        $pool = app(AiEnginePool::class);

        $devolvidos = [];
        if (! $this->option('so-sondar')) {
            $devolvidos = $pool->zelarLeases('guardiao');
        }

        $sonda = ['sondados' => 0, 'vivas' => 0, 'mortas' => 0, 'pulados' => [], 'erro' => null];
        if (! $this->option('so-zelar')) {
            $sonda = $this->sondarSessoes((int) $this->option('timeout'));
        }

        $this->line(json_encode([
            'travas_devolvidas' => $devolvidos,
            'sessao'            => $sonda,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    // -- sonda de sessao -------------------------------------------------------

    private function sondarSessoes(int $timeout): array
    {
        $alvos  = [];   // porta => engine
        $remote = null;
        $pulados = [];

        foreach (AiEngine::where('tool_type', 'video')->get() as $engine) {
            $cfg = is_array($engine->config_json) ? $engine->config_json
                 : (json_decode($engine->config_json ?? '{}', true) ?: []);

            if (empty($cfg['remote'])) {
                continue; // motor local (ex.: Reserva Mac) nao tem perfil de Chrome no PC
            }
            $remote = $remote ?: $cfg['remote'];

            $porta = (int) ($cfg['pc_port'] ?? $cfg['porta'] ?? 0);
            if ($porta <= 0) {
                continue;
            }

            // Motor com job rodando NAO e sondado: abrir aba num Chrome que esta
            // renderizando video de cliente e risco que nao vale a leitura.
            if ($this->estaTrancado((int) $engine->id)) {
                $pulados[] = $engine->name . '(gerando)';
                continue;
            }

            $alvos[$porta] = $engine;
        }

        if (! $alvos || ! $remote) {
            return ['sondados' => 0, 'vivas' => 0, 'mortas' => 0, 'pulados' => $pulados, 'erro' => 'sem_alvos'];
        }

        $leituras = $this->rodarSondaNoPc(array_keys($alvos), $remote, $timeout);
        if ($leituras === null) {
            return ['sondados' => 0, 'vivas' => 0, 'mortas' => 0, 'pulados' => $pulados, 'erro' => 'sonda_nao_rodou'];
        }

        $vivas = 0; $mortas = 0; $mudou = [];

        foreach ($leituras as $l) {
            $porta  = (int) ($l['porta'] ?? 0);
            $engine = $alvos[$porta] ?? null;
            if (! $engine) {
                continue;
            }

            // RELEITURA OBRIGATORIA antes de escrever. Entre a leitura la em cima e
            // agora passaram ~8s de SSH, e o `video:frota-sync` roda no MESMO minuto
            // escrevendo OUTRAS chaves do mesmo config_json (pc_status,
            // precisa_reconectar). Sem o refresh, o ultimo a gravar apagaria o
            // trabalho do outro -- e apagar `precisa_reconectar` poria motor
            // deslogado de volta no pool, exatamente o contrario deste ticket.
            $engine->refresh();
            $cfg = is_array($engine->config_json) ? $engine->config_json
                 : (json_decode($engine->config_json ?? '{}', true) ?: []);

            $valida = (bool) ($l['valida'] ?? false);
            $motivo = (string) ($l['motivo'] ?? 'desconhecido');

            // "sem_chrome" (CDP nao respondeu) NAO e veredito de sessao: pode ser o
            // perfil reiniciando. Nesse caso a gente NAO carimba invalido -- deixa a
            // leitura anterior envelhecer e o pool cair em fail-open. Derrubar motor
            // por porta muda seria trocar um falso-negativo por um falso-positivo.
            if (! $valida && str_starts_with($motivo, 'sem_chrome')) {
                $cfg['sessao_motivo']   = $motivo . ' [fonte: lease-guardiao/cdp-mudo]';
                $cfg['sessao_sonda_em'] = now()->toDateTimeString();
                // NAO mexe em sessao_valida / sessao_vista_em: CDP mudo nao e veredito.
                $engine->update(['config_json' => $cfg]);
                $pulados[] = $engine->name . '(cdp_mudo)';
                continue;
            }

            $antes = $cfg['sessao_valida'] ?? null;

            // DONO DESTES CAMPOS: video:lease-guardiao (este comando). Fonte da
            // verdade = labs.google/fx/api/auth/session aberto DENTRO do perfil.
            // Quem LE pra decidir reserva e o AiEnginePool::estadoDaFrota().
            //
            // NAO CONFUNDIR com `sessao_viva`, que mora no mesmo config_json mas e
            // do /root/scripts/vigia-saldo.php (monitor de saldo do Ruan). Dois
            // campos parecidos, dois donos, propositos diferentes -- por isso o
            // motivo abaixo carrega a assinatura da fonte.
            $cfg['sessao_valida']   = $valida;
            $cfg['sessao_email']    = $l['email'] ?? null;
            $cfg['sessao_expira']   = $l['expira'] ?? null;
            $cfg['sessao_vista_em'] = now()->toDateTimeString();
            $cfg['sessao_motivo']   = $motivo . ' [fonte: lease-guardiao/labs.google-auth-session]';
            $cfg['sessao_sonda_em'] = now()->toDateTimeString();

            $engine->update(['config_json' => $cfg]);

            $valida ? $vivas++ : $mortas++;

            if ($antes !== $valida) {
                $mudou[] = $engine->name . ': sessao ' . ($valida ? 'VOLTOU (' . ($l['email'] ?? '?') . ')' : 'CAIU (' . $motivo . ')');
                if (! $valida) {
                    Log::error('[SEL-LEASE][guardiao] motor SEM SESSAO Google, fora da roda ate relogar', [
                        'engine' => $engine->name, 'porta' => $porta, 'motivo' => $motivo,
                    ]);
                } else {
                    Log::info('[SEL-LEASE][guardiao] motor com sessao viva, de volta a roda', [
                        'engine' => $engine->name, 'porta' => $porta, 'conta' => $l['email'] ?? null,
                    ]);
                }
            }
        }

        return [
            'sondados' => $vivas + $mortas,
            'vivas'    => $vivas,
            'mortas'   => $mortas,
            'pulados'  => $pulados,
            'mudancas' => $mudou,
            'erro'     => null,
        ];
    }

    /**
     * Roda a sonda DENTRO dos perfis do PC e devolve a lista de leituras.
     *
     * O script vai pelo STDIN do `node` (node executa stdin quando nao recebe
     * arquivo) -- e assim a gente le do PC sem criar nem alterar arquivo la, que
     * e regra dura enquanto outro agente trabalha na maquina.
     *
     * @return array<int,array>|null  null = nao deu pra ler (fail-open)
     */
    private function rodarSondaNoPc(array $portas, array $remote, int $timeout): ?array
    {
        $wdir = $remote['worker_dir'] ?? 'C:\\sellerglobal-render';
        $js   = $this->scriptSonda($portas);

        $p = new Process(array_merge(['ssh'], [
            '-i', $remote['ssh_key'] ?? '/home/api.seller.global/.ssh/pc-render',
            '-p', (string) ($remote['port'] ?? 2200),
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120',
            '-o', 'ConnectTimeout=8', '-o', 'ServerAliveInterval=5',
            ($remote['user'] ?? 'ruan') . '@' . ($remote['host'] ?? 'localhost'),
            'cd "' . $wdir . '" && node',
        ]));
        $p->setTimeout(max(20, $timeout));
        $p->setInput($js);

        try {
            $p->run();
        } catch (\Throwable $e) {
            Log::warning('[SEL-LEASE][guardiao] sonda de sessao nao rodou', [
                'err' => mb_substr($e->getMessage(), 0, 160),
            ]);
            return null;
        }

        $saida = $p->getOutput();
        $pos   = strpos($saida, '###SESSAO###');
        if ($pos === false) {
            Log::warning('[SEL-LEASE][guardiao] sonda sem marcador, ignorando leitura', [
                'exit' => $p->getExitCode(),
                'out'  => mb_substr(trim($saida . ' ' . $p->getErrorOutput()), 0, 200),
            ]);
            return null;
        }

        $json = trim(substr($saida, $pos + strlen('###SESSAO###')));
        $j    = json_decode($json, true);

        return is_array($j) ? $j : null;
    }

    /**
     * O script da sonda. Portas embutidas -> nenhuma citacao de shell no caminho.
     *
     * REGRA DE OURO AQUI: **nunca chamar `browser.close()`**.
     *
     * O `_prova5.js` (script manual que ja existia no PC) fecha o browser no
     * finally. Num browser obtido por `connectOverCDP` isso nao e "desconectar":
     * dependendo da versao do Playwright, fecha o CHROME INTEIRO -- um Chrome que
     * nao e nosso. Ele e do pool_daemon, e as vezes e do proprio Ruan (o daemon
     * cede o perfil quando ve `.humano.lock` / janela aberta). Rodando de 3 em 3
     * minutos, um `close()` desses seria uma mao invisivel fechando a janela de
     * quem estiver trabalhando na conta.
     *
     * Aqui a gente fecha SO a aba que a gente abriu e deixa a conexao cair
     * sozinha quando o node terminar -- que e daqui a milissegundos. Custo zero,
     * risco zero.
     *
     * (Investigado hoje as 21:35 justamente porque a frota foi a 0 Chrome e a
     * primeira suspeita fui eu. Nao era -- o pool_daemon foi reiniciado as 21:32 e
     * ele mata os Chromes orfas do robo no start, esta no pool_daemon.log. Mas o
     * risco do close() era real e ficou fechado de qualquer forma.)
     */
    private function scriptSonda(array $portas): string
    {
        $lista = implode(',', array_map('intval', $portas));

        return <<<JS
const { chromium } = require('playwright');
const PORTAS = [{$lista}];
(async () => {
  const out = [];
  for (const porta of PORTAS) {
    let browser, r = { porta, valida: false, motivo: 'desconhecido', email: null, expira: null };
    try {
      browser = await chromium.connectOverCDP('http://127.0.0.1:' + porta, { timeout: 10000 });
      const page = await browser.contexts()[0].newPage();
      await page.goto('https://labs.google/fx/api/auth/session', { waitUntil: 'domcontentloaded', timeout: 30000 });
      const t = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ');
      const m = t.match(/"email":"([^"]+)"/);
      const exp = (t.match(/"expires":"([^"]+)"/) || [, ''])[1];
      if (m) { r.valida = true; r.email = m[1]; r.expira = exp || null; r.motivo = 'ok'; }
      else { r.motivo = 'sessao_vazia'; }
      await page.close();
    } catch (e) { r.motivo = 'sem_chrome:' + String(e.message).slice(0, 50); }
    finally {
      // NUNCA browser.close(): o Chrome nao e nosso (pool_daemon / janela do Ruan).
      // So a aba que abrimos e fechada, logo acima.
    }
    out.push(r);
  }
  console.log('###SESSAO###' + JSON.stringify(out));
  // O socket CDP aberto segura o event loop do node -- sem isto o processo NAO
  // termina e a sonda inteira estoura o timeout (medido: virou `sonda_nao_rodou`
  // em toda rodada). process.exit derruba o socket do NOSSO lado, sem pedir
  // nada ao Chrome. E a saida que fecha a conexao sem fechar o navegador alheio.
  process.exit(0);
})();
JS;
    }

    /**
     * O motor esta com a trava do pool tomada (= job rodando nele agora)?
     *
     * Conexao = lock_connection, prefixo = do store. Mesma pegadinha documentada
     * em reconcilia-reservas.php: olhar na conexao errada devolve "livre" com o
     * lock na mao -- e aqui isso significaria abrir aba num Chrome renderizando
     * video de cliente. Por isso o catch devolve TRUE: na duvida, nao sonda.
     */
    private function estaTrancado(int $engineId): bool
    {
        try {
            $store  = Cache::store('redis')->getStore();
            $prefix = method_exists($store, 'getPrefix') ? (string) $store->getPrefix() : (string) config('cache.prefix');
            $conn   = \Illuminate\Support\Facades\Redis::connection(
                config('cache.stores.redis.lock_connection') ?: 'default'
            );

            return (bool) $conn->exists($prefix . 'ai_engine_lock:' . $engineId);
        } catch (\Throwable $e) {
            return true; // na duvida NAO sonda (nunca abrir aba em motor que pode estar gerando)
        }
    }
}
