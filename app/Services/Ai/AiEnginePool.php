<?php
namespace App\Services\Ai;

use App\Models\AiEngine;
use App\Models\AiEngineUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-429 -- Pool universal de motores IA.
 * SEL-456 -- Lock Redis por perfil + quota tracking + fila FIFO multi-cliente.
 *
 * Interface fluente:
 *   app(AiEnginePool::class)->for('video')->generate($taskId, $kind, $payload)
 *   app(AiEnginePool::class)->for('llm')->chat($messages)
 *   app(AiEnginePool::class)->for('image')->generateImage($prompt)
 *   app(AiEnginePool::class)->for('scraping')->scrape($url)
 *
 * Pool com lock:
 *   $engine = app(AiEnginePool::class)->reserveEngine('video', 'image2video');
 *   // usa $engine->id para passar ao adapter
 *   app(AiEnginePool::class)->releaseEngine($engine->id, success: true|false);
 *
 * Quotas por engine (config_json):
 *   quota_type: 'ultra' => 50/dia | 'free' => 5/dia | ausente => 10/dia
 *   quota_per_day: N (override explicito, prevalece sobre quota_type)
 *
 * VideoEnginePool::generate() continua funcionando como wrapper deste pool
 * (backward compat durante migracao -- SEL-425 nao quebra).
 */
class AiEnginePool
{
    /** SEL-SALDOZERO: credito minimo pra um motor entrar no pool (Omni Flash = 15/video). */
    private const SALDO_MINIMO = 20;

    /**
     * SEL-ENGRENAGEM (12/08) — no MODO GRATIS o credito nao e o portao.
     *
     * O guarda SALDO_MINIMO nasceu certo (conta seca raspava mp4 velho da galeria e
     * devolvia como geracao nova). Mas ele olha SALDO, e no "Veo 3.1 - Lite [Lower
     * Priority]" a geracao custa 0 credito -- conta com 3 creditos gera igual. Medido
     * agora (12/08 19h): motores 25/26/27 estavam com sessao VIVA e prontos no PC e
     * mesmo assim ficavam FORA do pool por saldo 7/7/3. Sobrava 1 motor (24) pra
     * frota inteira -> fila de espera de 9min por clipe -> "demorou demais" pro
     * cliente.
     *
     * A protecao contra video-velho NAO some: ela vive no worker do PC
     * (veo_generate.js, "PROVA DE GERACAO NOVA" com baseline de genMediaIds), que
     * rejeita qualquer mp4 que ja existia antes da geracao. O saldo era so um proxy
     * grosseiro pra isso -- e um proxy que, no gratis, e sempre falso-positivo.
     */
    private const MODELOS_SEM_CUSTO = ['lower priority'];

    /** Falha mais nova que isto joga o motor pro fim da fila (mas NAO o tira do pool). */
    private const JANELA_FALHA_MIN = 30;

    /** Leitura de estado do PC (pool.json) mais velha que isto e ignorada (fail-open). */
    private const FROTA_FRESCA_S = 900;

    /** TTL do lock Redis por perfil DICloak: 10 min. */
    private const LOCK_TTL = 600;

    /**
     * SEL-LEASE (12/08) — O LEASE, e por que ele nao e o lock.
     *
     * O lock Redis responde "alguem pegou este motor?". Ele NAO responde "esse
     * alguem ainda esta vivo?". Medido em ai_engine_usage do dia 12/08 (UTC):
     * motor 24 gen=15 res=11, motor 25 gen=14 res=9, motor 26 gen=27 res=7,
     * motor 27 gen=42 res=6, motor 29 gen=0 res=3 -> 36 reservas que NUNCA foram
     * devolvidas contra 98 geracoes concluidas. Cada uma dessas segurou o motor
     * ate o TTL de 600s e ainda contou pra quota do dia (o pool le
     * generated+reserved), ou seja: motor vivo aparecendo como cheio.
     *
     * A causa e sempre a mesma: o processo morre SEM rodar o finally (SIGALRM do
     * queue:work, supervisor derrubando worker, OOM). Nao existe codigo no
     * caminho de saida que possa consertar isso -- por definicao ele nao roda.
     *
     * Entao o dono do motor passa a ter que PROVAR que esta vivo: enquanto o
     * render anda, o adapter renova o lease (LEASE_BATIDA_S). Se o batimento
     * para, o lease vence em LEASE_TTL e o motor volta pro pool na hora, sem
     * esperar os 10 minutos do lock. Render legitimo demora minutos e continua
     * protegido -- o que distingue trabalho de travamento e a batida, nao o
     * relogio.
     */
    private const LEASE_TTL = 90;

    /** De quanto em quanto tempo o render vivo renova o lease. 3 batidas de folga. */
    public const LEASE_BATIDA_S = 30;

    /**
     * SEL-LEASE — leitura de sessao mais velha que isto NAO vale como veredito.
     * Fail-open de proposito: se a sonda parar de rodar, a frota volta a se
     * comportar como antes do ticket em vez de travar sozinha.
     *
     * 10 min = a sonda roda de 3 em 3 min (cadencia do keep-alive do PC, pra nao
     * somar batida de robo na conta do Google), entao cabem DUAS rodadas perdidas
     * antes de o veredito caducar. Com o valor colado no intervalo (5 min), uma
     * unica rodada atrasada devolveria conta morta pro pool -- justamente o que
     * este ticket veio impedir. Errar pro lado de "espera mais um pouco" aqui e
     * barato: sessao morta nao volta sozinha, e quando volta a proxima sonda
     * conserta em ate 3 min.
     */
    /**
     * SEL-DIVIDA (13/08) — PUBLIC de proposito: esta e a UNICA definicao de
     * "sonda fresca" do sistema. Antes existiam DUAS janelas para a MESMA leitura
     * (`config_json.sessao_vista_em`): 600s aqui e `subMinutes(15)` no
     * VideoFrotaSync. Entre 10 e 15 minutos o pool considerava a sonda VENCIDA
     * (motor fora, `sem_sessao_google`) enquanto o sync ainda a considerava VIVA
     * (e reescrevia pc_status=ready por cima). Os dois liam o mesmo campo e
     * discordavam — janela de 5 min em que a frota nao tinha veredito estavel.
     * Quem precisar da janela IMPORTA daqui; nao redefina o numero.
     */
    public const SESSAO_FRESCA_S = 600;

    /**
     * SEL-DIVIDA (13/08) — PALPITE DE ULTIMO RECURSO, nao a regra.
     *
     * Estes tres numeros nunca foram medidos contra o Google. Hoje eles sao
     * CAMINHO MORTO para toda engine de video ativa: 23/25/26/27 tem
     * quota_per_day=60 e 24/29..34 tem 200, todos explicitos no config_json —
     * nenhuma cai aqui. Ficam so como rede pra engine nova que entre sem config.
     *
     * O que existe de MEDIDO sobre o teto real do Flow gratuito
     * ("Veo 3.1 - Lite [Lower Priority]"), lido de ai_engine_usage:
     *   11/08: engine 27 = 57 geracoes, 26 = 53, 25 = 51, 23 = 49, 24 = 49
     *          — cinco contas passando de 49 no mesmo dia, ZERO recusa do Google
     *            registrada (nenhum erro de quota/limite em ai_video_pipelines nem
     *            nos logs do worker; as falhas do dia foram timeout e worker morto).
     * Ou seja: o teto real por conta e >= 57/dia e NAO foi encontrado. Por isso o
     * valor explicito de 60 nas contas familia e conservador de proposito, e o de
     * 200 nas sellerglobal ainda esta longe de ter sido testado.
     *
     * NAO foi medido ate recusar de proposito — ver relatorio SEL-DIVIDA: gerar em
     * rajada ate o Google negar e exatamente o padrao de robo que o resto do sistema
     * gasta energia pra evitar (cadencia do keep-alive, IP residencial), e o preco de
     * errar e login wall numa conta que so o Ruan reloga na mao.
     */
    private const QUOTA_DEFAULTS = [
        'ultra'   => 50,
        'free'    => 5,
        'default' => 10,
    ];

    private string $toolType = 'video';

    /**
     * SEL-LEASE — geracao do lease por motor, viva SO na memoria deste processo.
     *
     * De proposito NAO mora no objeto AiEngine: qualquer propriedade nova num
     * model do Eloquent vira ATRIBUTO, e o proximo `$engine->update()` /
     * `recordFailure()` tentaria gravar uma coluna `_pool_lease_ger` que nao
     * existe. Reserva, batida e devolucao acontecem todas no mesmo processo do
     * job -- entao um mapa estatico id => geracao e suficiente e nao contamina o
     * model. O AiEnginePool e singleton no container, mas o mapa e static pra
     * funcionar mesmo se alguem instanciar a classe na mao.
     *
     * @var array<int,int>
     */
    private static array $geracaoPorMotor = [];

    /** Quando este processo abriu o lease de cada motor (epoch). @var array<int,int> */
    private static array $aberturaPorMotor = [];

    // -- Pool / Lock / Quota ---------------------------------------------------

    /**
     * SEL-456: Reserva o melhor engine disponivel para o toolType/kind solicitado.
     *
     * Algoritmo FIFO + round-robin:
     *   1. Busca engines ativos sem cooldown, ordenados por priority (menor primeiro).
     *   2. Para cada engine, tenta adquirir lock Redis (TTL=600s).
     *   3. Verifica quota diaria (generated_count + reserved_count < quota_per_day).
     *   4. Se OK: incrementa reserved_count atomicamente, retorna o engine.
     *   5. Se nenhum disponivel: lanca RuntimeException.
     *
     * @param  string $toolType  'video' | 'image' | 'llm' | 'scraping'
     * @param  string $kind      hint do tipo de tarefa (ex: 'image2video', 'text2video')
     * @return AiEngine          engine reservado (caller DEVE chamar releaseEngine no finally)
     * @throws \RuntimeException se nenhum engine tiver lock livre + quota disponivel
     */
    public function reserveEngine(string $toolType, string $kind = 'default'): AiEngine
    {
        $engines = AiEngine::availableFor($toolType);

        if ($engines->isEmpty()) {
            throw new \RuntimeException(
                "sel456_no_engines: nenhum engine {$toolType} ativo no pool"
            );
        }

        // SEL-ENGRENAGEM (12/08) — DISTRIBUICAO, nao "primeiro da lista".
        // availableFor() devolve ordenado por priority; com 12 motores no gratis isso
        // desperdicava a frota (o de priority 1 levava tudo). Aqui reordenamos por
        // QUEM MERECE O PROXIMO JOB. Ver ordenarParaDistribuir().
        if ($toolType === 'video') {
            $engines = $this->ordenarParaDistribuir($engines);
        }

        $lockStore = $this->lockStore();
        $tried     = [];

        foreach ($engines as $engine) {
            // AUTO-CURA host-probe (Fase 1 item 3): antes de RESERVAR um motor REMOTO
            // (roda no PC do Ruan via tunel reverso), confere se o HOST responde. Se o
            // PC caiu (reboot / tunel fora), pula os 5 remotos JUNTOS -- resultado
            // CACHEADO ~10s (o watchdog video:pc-autocura tambem prima este cache) pra
            // NAO sondar por job -- e cai direto no motor LOCAL de reserva na hora, sem
            // queimar lock/quota/tentativa num motor morto. Aditivo: motor sem `remote`
            // nunca sonda (segue o caminho de sempre).
            $engCfg = is_array($engine->config_json) ? $engine->config_json
                    : (json_decode($engine->config_json ?? '{}', true) ?: []);

            // SEL-SALDOZERO (12/08) — GUARDA DE SALDO MINIMO.
            //
            // Descoberto hoje 15h: quando a conta do Flow fica sem credito, o worker
            // NAO da erro. Ele raspa um mp4 ANTIGO da galeria da conta e devolve como
            // se fosse a geracao nova, com "ok":true. Medido: genIds=0, saldo_antes ==
            // saldo_depois, took_s ~30s (render real leva 56-135s). Consequencia: o
            // cliente recebia o video de OUTRA pessoa — inclusive a propaganda do
            // proprio Ruan — e ninguem via, porque a pipeline terminava 'done'.
            // Ultima geracao REAL foi 12/08 14:38; tudo depois disso era reciclado.
            //
            // Aqui a gente prefere FALHAR ALTO a entregar video errado: motor sem
            // credito pra pelo menos um video sai do pool. Custo medido do Omni Flash:
            // 15 creditos/video. Margem de seguranca -> 20.
            // SEL-ENGRENAGEM (12/08) — PORTAO DE FROTA. Motor que o PC diz estar
            // deslogado (login_wall), ou que alguem tirou do ar DE PROPOSITO, nao pode
            // ser reservado: reservar um motor morto queima 420-720s de timeout no
            // cliente antes de rodar pro proximo. Ver estadoDaFrota().
            if ($motivo = $this->estadoDaFrota($engine, $engCfg)) {
                $tried[] = $engine->name . '(' . $motivo . ')';
                Log::warning('[SEL-ENGRENAGEM][AiEnginePool] motor fora da frota, pulando', [
                    'engine' => $engine->name,
                    'motivo' => $motivo,
                    'kind'   => $kind,
                ]);
                // SEL-LEASE: o pulo por SESSAO vai pro diario (o laravel.log come
                // warning). Uma linha por motor a cada 60s -- o suficiente pra provar
                // o comportamento sem virar enxurrada na hora de pico.
                if ($motivo === 'sem_sessao_google'
                    && Cache::add('lease_diario_sessao:' . $engine->id, 1, 60)) {
                    self::diario('PULEI_MOTOR_SEM_SESSAO', [
                        'engine_id' => $engine->id,
                        'engine'    => $engine->name,
                        'perfil'    => $engCfg['perfil_pc'] ?? $engCfg['profile'] ?? null,
                        'conta'     => $engCfg['sessao_email'] ?? $engCfg['conta_email'] ?? null,
                        'motivo'    => $engCfg['sessao_motivo'] ?? null,
                        'visto_em'  => $engCfg['sessao_vista_em'] ?? null,
                        'kind'      => $kind,
                    ]);
                }
                continue;
            }

            $saldo = $engCfg['saldo'] ?? null;
            if ($saldo !== null && (int) $saldo < self::SALDO_MINIMO && ! $this->modoGratis($engCfg)) {
                $tried[] = $engine->name . '(saldo_' . (int) $saldo . ')';
                Log::error('[SEL-SALDOZERO][AiEnginePool] motor SEM CREDITO, fora do pool', [
                    'engine' => $engine->name,
                    'saldo'  => (int) $saldo,
                    'minimo' => self::SALDO_MINIMO,
                    'kind'   => $kind,
                ]);
                continue;
            }

            if (!empty($engCfg['remote']) && !$this->remoteHostHealthy($engCfg['remote'])) {
                $tried[] = $engine->name . '(host_down)';
                Log::warning('[AUTO-CURA][AiEnginePool] host remoto fora, pulando motor', [
                    'engine' => $engine->name,
                    'kind'   => $kind,
                ]);
                continue;
            }

            // SEL-LEASE (12/08) — ZELADOR NA PORTA DA RESERVA.
            // Antes de tentar o lock, confere se a trava deste motor e ORFA (existe
            // trava, mas ninguem batendo). Se for, devolve NA HORA -- em vez de o
            // proximo cliente esperar o TTL de 600s vencer sozinho. Roda aqui alem do
            // comando agendado porque este e o momento em que a resposta importa.
            $this->devolverSeOrfao((int) $engine->id, 'reserva');

            $lockKey = 'ai_engine_lock:' . $engine->id;
            $lock    = $lockStore->lock($lockKey, self::LOCK_TTL);

            if (!$lock->get()) {
                $tried[] = $engine->name . '(locked)';
                Log::debug('[SEL-456][AiEnginePool] engine locked, pulando', [
                    'engine' => $engine->name,
                    'kind'   => $kind,
                ]);
                continue;
            }

            // ══ SEL-ECOSSISTEMA (12/08) — A TRAVA TEM QUE SER DA CONTA, NAO DO PERFIL ══
            //
            // MEDIDO HOJE: a frota tem 11 "motores", mas eles NAO sao 11 contas Google.
            // A sonda (que pergunta o e-mail direto pra labs.google) devolveu isto:
            //
            //   motor sellerglobal02  -> na verdade e sellerglobal06@gmail.com
            //   motor sellerglobal03  -> na verdade e sellerglobal04@gmail.com
            //   motor sellerglobal04  -> na verdade e sellerglobal02@gmail.com
            //   motor sellerglobal05  -> na verdade e sellerglobal01@gmail.com   <---
            //   motor sellerglobal06  -> na verdade e sellerglobal03@gmail.com
            //   motor sellerglobal01  -> sellerglobal01@gmail.com                <---
            //
            // Os cofres de sessao foram gravados trocados. A consequencia grave e a
            // ultima linha: sellerglobal01 e sellerglobal05 sao A MESMA CONTA GOOGLE.
            // Como o lock era por engine_id, o pool podia reservar os DOIS ao mesmo
            // tempo — e dois jobs na mesma conta e a condicao exata que produz o
            // vazamento (mesma galeria, mesma fila de geracao, mesmos gen-ids).
            //
            // A trava passa a ser pela CONTA REAL (a que a sonda viu; o nome do motor
            // nao serve de identidade porque esta comprovadamente errado). Um pedido
            // por conta Google, sempre — quantas linhas de motor apontem pra ela.
            //
            // Fail-open: motor sem conta conhecida nao ganha trava de conta (segue como
            // antes). A trava de conta e ADICIONAL, nunca substitui a do motor.
            $contaLock = null;
            $conta = $this->contaReal($engCfg);
            if ($conta !== null) {
                $contaKey  = 'ai_conta_lock:' . $conta;
                $contaLock = $lockStore->lock($contaKey, self::LOCK_TTL);
                if (! $contaLock->get()) {
                    // Alguem ja esta gerando NESTA conta por outro motor. Devolve o
                    // lock do motor e segue pro proximo — sem queimar quota.
                    try { $lock->release(); } catch (\Throwable $e) {}
                    $tried[] = $engine->name . '(conta_ocupada:' . $conta . ')';
                    Log::warning('[SEL-ECOSSISTEMA][AiEnginePool] conta Google ja ocupada por outro motor, pulando', [
                        'engine' => $engine->name,
                        'conta'  => $conta,
                        'nota'   => 'dois motores apontam pra mesma conta; um pedido por conta',
                        'kind'   => $kind,
                    ]);
                    continue;
                }
            }

            // Lock adquirido -- verificar quota
            $usage = AiEngineUsage::todayFor($engine->id);
            $quota = $this->resolveQuota($engine);

            if ($usage->totalToday() >= $quota) {
                // Quota esgotada hoje -- liberar lock e tentar proximo
                $lock->release();
                // SEL-ECOSSISTEMA: a trava de conta sai JUNTO. Esquecer ela aqui
                // prenderia a conta inteira por 600s so porque um motor dela bateu
                // a quota do dia.
                if ($contaLock) { try { $contaLock->release(); } catch (\Throwable $e) {} }
                $tried[] = $engine->name . '(quota_full:' . $usage->totalToday() . '/' . $quota . ')';
                Log::info('[SEL-456][AiEnginePool] quota esgotada, pulando engine', [
                    'engine'         => $engine->name,
                    'generated'      => $usage->generated_count,
                    'reserved'       => $usage->reserved_count,
                    'quota_per_day'  => $quota,
                ]);
                continue;
            }

            // Tudo OK -- incrementar reserved_count e registrar reserva
            DB::table('ai_engine_usage')
                ->where('id', $usage->id)
                ->update([
                    'reserved_count' => DB::raw('reserved_count + 1'),
                    'last_used_at'   => now(),
                    'updated_at'     => now(),
                ]);

            // SEL-LEASE: abre o lease (numero de geracao + batimento). A partir daqui
            // este job e o DONO desta geracao do motor; qualquer outro processo que
            // ainda pense que e dono vira zumbi e nao pode escrever resultado.
            $geracao = $this->abrirLease($engine, $kind);

            Log::info('[SEL-456][AiEnginePool] engine reservado', [
                'engine'        => $engine->name,
                'engine_id'     => $engine->id,
                'kind'          => $kind,
                'tool_type'     => $toolType,
                'total_hoje'    => $usage->totalToday() + 1,
                'quota_per_day' => $quota,
                'lock_key'      => $lockKey,
                'lease_geracao' => $geracao,
            ]);

            // Armazenar referencia ao lock no engine (para releaseEngine)
            $engine->_pool_lock     = $lock;
            $engine->_pool_lock_key = $lockKey;
            // SEL-ECOSSISTEMA: a trava de conta viaja junto com o motor, pra o
            // releaseEngine devolver as duas na mesma disciplina (inclusive no
            // `finally` do job, que e quem cobre as saidas inesperadas).
            $engine->_pool_conta_lock = $contaLock;
            $engine->_pool_conta      = $conta;

            return $engine;
        }

        // Nenhum engine disponivel
        $reason = implode(', ', $tried);
        throw new \RuntimeException(
            "sel456_all_engines_unavailable:{$toolType}:{$kind} tried=[$reason]"
        );
    }

    // -- SEL-ENGRENAGEM: distribuicao + portao de frota -------------------------

    /**
     * SEL-ENGRENAGEM (12/08, Ruan: "12 motores rodando tudo ao mesmo tempo").
     *
     * Ordena os motores por QUEM MERECE O PROXIMO JOB, em vez de "o primeiro da
     * lista que estiver livre". Criterio, em ordem:
     *
     *   1. FALHA RECENTE (<30min) -> pro fim. Nao tira do pool (o cooldown ja faz
     *      isso quando e pra tirar); so evita empilhar job em cima de quem acabou
     *      de tropecar enquanto existe motor limpo parado.
     *   2. QUEM GEROU MENOS HOJE -> primeiro. E o round-robin justo: ao longo do
     *      dia todo motor converge pra mesma quantidade de geracoes, em vez de uma
     *      conta levar 57 e outra 10 (medido em ai_engine_usage 11/08).
     *   3. QUEM ESTA OCIOSO HA MAIS TEMPO -> primeiro (last_used_at ASC). E o
     *      desempate que faz 12 reservas seguidas cairem em 12 motores diferentes:
     *      reservar carimba last_used_at, entao o proximo pedido ja acha outro no
     *      topo. Sem isso, 12 pedidos no mesmo segundo (empate de uso) voltariam
     *      sempre na mesma ordem e a distribuicao dependeria so do lock.
     *   4. priority ASC / id ASC -> desempate final, estavel e previsivel.
     *
     * NOTA sobre priority: ela deixou de ser o criterio PRINCIPAL de proposito. Com
     * os 12 motores no modo gratis eles sao intercambiaveis; manter priority no topo
     * significava mandar tudo pro motor 24 (priority 1) e deixar 11 parados, que e
     * exatamente o desperdicio que este ticket veio resolver. Ela sobrevive como
     * desempate pra continuar honrando preferencia declarada entre iguais.
     *
     * A EXCLUSIVIDADE (nunca dois jobs na mesma janela) continua sendo do lock Redis
     * `ai_engine_lock:<id>` -- esta funcao so decide a ORDEM em que tentamos pegar o
     * lock. Reordenar nao pode, sozinho, causar dobra.
     *
     * @param  \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $engines
     * @return array<int,AiEngine>
     */
    private function ordenarParaDistribuir($engines): array
    {
        $lista = $engines instanceof \Illuminate\Support\Collection ? $engines->all() : (array) $engines;
        if (count($lista) < 2) {
            return array_values($lista);
        }

        $hoje = now()->utc()->toDateString();
        $uso  = [];
        try {
            $uso = DB::table('ai_engine_usage')
                ->where('date', $hoje)
                ->get(['engine_id', 'generated_count', 'reserved_count', 'last_used_at'])
                ->keyBy('engine_id')
                ->all();
        } catch (\Throwable $e) {
            // fail-open: sem tabela de uso a gente ainda distribui por last_failure/priority
            Log::warning('[SEL-ENGRENAGEM] nao consegui ler ai_engine_usage, ordem degradada', [
                'err' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }

        $limiteFalha = now()->subMinutes(self::JANELA_FALHA_MIN);
        $chaves      = [];

        foreach ($lista as $i => $e) {
            $u        = $uso[$e->id] ?? null;
            $geradas  = $u ? ((int) ($u->generated_count ?? 0) + (int) ($u->reserved_count ?? 0)) : 0;
            $ocioso   = PHP_INT_MIN; // nunca usado hoje = o mais ocioso de todos
            if ($u && ! empty($u->last_used_at)) {
                try { $ocioso = \Illuminate\Support\Carbon::parse($u->last_used_at)->getTimestamp(); } catch (\Throwable $x) {}
            }

            $tropecou = 0;
            if ($e->last_failure_at) {
                try {
                    $tropecou = \Illuminate\Support\Carbon::parse($e->last_failure_at)->gt($limiteFalha) ? 1 : 0;
                } catch (\Throwable $x) {}
            }

            $chaves[$i] = [$tropecou, $geradas, $ocioso, (int) $e->priority, (int) $e->id];
        }

        $ordem = array_keys($chaves);
        usort($ordem, function ($a, $b) use ($chaves) {
            return $chaves[$a] <=> $chaves[$b];
        });

        $saida = [];
        foreach ($ordem as $i) { $saida[] = $lista[$i]; }

        Log::debug('[SEL-ENGRENAGEM] ordem de distribuicao', [
            'ordem' => array_map(fn($e) => $e->id, $saida),
        ]);

        return $saida;
    }

    /**
     * SEL-ENGRENAGEM (12/08) — este motor pode receber job AGORA?
     *
     * Devolve NULL quando esta apto, ou o MOTIVO (string curta) quando nao esta.
     * Tres portoes, todos vindos de fatos medidos hoje:
     *
     *  a) `fora_do_ar_motivo` / `desligado_manual`: alguem tirou do ar DE PROPOSITO
     *     (ex.: motor 14 roda a copia ANTIGA do worker, sem o portao anti-video-velho
     *     -> se voltar, entrega video de outro cliente). O sentinela-unico.sh ja
     *     respeitava essa chave pra nao RELIGAR; o pool NAO respeitava pra nao
     *     RESERVAR -- o motor seguia is_active=1 e era escolhido normalmente. Este e
     *     o portao que faltava, e ele fica ANTES de qualquer outro.
     *
     *  b) `precisa_reconectar`: a sessao do Google caiu pedindo SENHA. So o Ruan
     *     resolve (regra dura: nao tentamos logar). Fica fora ate a flag ser limpa.
     *
     *  c) `pc_status` != ready: o pool_daemon do PC publica pool.json com o estado
     *     REAL de cada perfil; `video:frota-sync` traz isso pro banco. Medido 12/08
     *     19h: o banco tinha 12 motores "ativos" e o PC tinha 4 prontos -- os outros
     *     7 em login_wall. Reservar um desses so servia pra queimar o timeout do
     *     cliente. Leitura velha (>15min) e IGNORADA (fail-open): se o sync parar de
     *     rodar, a frota volta a se comportar como antes, nunca trava sozinha.
     */
    private function estadoDaFrota(AiEngine $engine, array $cfg): ?string
    {
        foreach (['fora_do_ar_motivo', 'desligado_manual'] as $chave) {
            $v = $cfg[$chave] ?? null;
            if (! empty($v) && $v !== false && $v !== '0') {
                return 'desligado_manual';
            }
        }

        if (! empty($cfg['precisa_reconectar'])) {
            return 'precisa_reconectar';
        }

        // ══ SEL-ECOSSISTEMA (12/08) — QUEM BATEU A CARA NA PORTA TEM 10 MINUTOS ══
        //
        // A sonda e o worker olham COISAS DIFERENTES do mesmo motor:
        //   sonda  -> abre o cofre de cookies (sessoes\<motor>.json) e ve sessao boa
        //   worker -> entra no Chrome quente (porta CDP) e ve TELA DE LOGIN
        // Os dois podem estar certos ao mesmo tempo. Enquanto so a sonda mandava,
        // o motor voltava pra roda a cada 3 minutos pra falhar de novo: medido,
        // sellerglobal01 derrubou 9 tentativas seguidas em ~14s cada entre 22:48 e
        // 23:04 — cada uma dessas custou 14s do pedido de um cliente.
        //
        // Aqui o veredito de quem TENTOU vale por 10 minutos, e a sonda nao apaga
        // (o campo e exclusivo do worker). Passados os 10 min o motor volta a ser
        // tentado sozinho — se o Ruan relogou, ele volta; se nao, sai de novo. Sem
        // intervencao manual nas duas pontas.
        if (! empty($cfg['worker_loginwall_em'])) {
            try {
                if (\Illuminate\Support\Carbon::parse($cfg['worker_loginwall_em'])->gt(now()->subMinutes(10))) {
                    return 'worker_viu_login';
                }
            } catch (\Throwable $e) { /* data ilegivel -> fail-open */ }
        }

        // SEL-LEASE (12/08) — SONDA DE SESSAO: o portao que faltava.
        //
        // Todos os portoes acima acreditam em terceiros: numa flag que alguem
        // escreveu a mao, ou no que o pool_daemon do PC ACHA do perfil. Nenhum
        // deles PERGUNTA ao Google se a conta ainda esta logada. Medido hoje com
        // https://labs.google/fx/api/auth/session aberto DENTRO de cada perfil
        // (devolve {"user":{"email":...}} quando ha sessao e {} quando nao ha):
        // 6 contas vivas de 11. As outras 5 continuavam elegiveis pra receber job
        // -- e job em conta morta nao falha rapido, ele PENDURA ate o teto de
        // 420-720s segurando o motor.
        //
        // `video:lease-guardiao` carimba o veredito em config_json. Aqui a gente
        // so LE. Motor sem sessao sai da roda: nao e desativado, nao e marcado
        // precisa_reconectar (isso e decisao do sync/do Ruan) -- e so PULADO, com
        // o motivo no log e no `tried=[...]` da excecao.
        //
        // ATENCAO AO CAMPO CERTO -- existem DOIS parecidos em config_json, de
        // donos diferentes, e so um manda aqui:
        //   `sessao_valida` (ESTE)  <- video:lease-guardiao, pergunta ao Google
        //                              via labs.google/fx/api/auth/session. E o
        //                              unico que o pool le pra decidir reserva.
        //   `sessao_viva`           <- /root/scripts/vigia-saldo.php, monitor de
        //                              saldo do Ruan. Nao e lido por este codigo.
        // Se um dia forem unificados, o pool tem que apontar pro sobrevivente --
        // e nao ficar lendo um campo que ninguem mais escreve.
        //
        // Fail-open em duas camadas: sem leitura nenhuma (chave ausente) nao
        // bloqueia; leitura mais velha que SESSAO_FRESCA_S tambem nao bloqueia.
        if (array_key_exists('sessao_valida', $cfg) && $cfg['sessao_valida'] === false) {
            $visto = $cfg['sessao_vista_em'] ?? null;
            if ($visto) {
                try {
                    if (\Illuminate\Support\Carbon::parse($visto)->gt(now()->subSeconds(self::SESSAO_FRESCA_S))) {
                        // Nome do motivo escolhido pra NAO conter nenhum termo da lista
                        // CONTEUDO do VideoResilience (ex.: "login_wall", "sessao
                        // expirada"): este texto viaja dentro da excecao de reserva, e
                        // se ele fosse classificado como CONTEUDO o pedido morreria
                        // como "recusado" em vez de voltar pra fila.
                        return 'sem_sessao_google';
                    }
                } catch (\Throwable $e) { /* data ilegivel -> fail-open */ }
            }
        }

        // SEL-SONDAMANDA (12/08) — a SONDA vence o pool.json quando ela diz que a
        // sessao esta viva.
        //
        // O pool_daemon do PC marca um perfil como `login_wall` e NUNCA re-verifica
        // (`if (e.status === 'login_wall') continue;`): ele fica esperando o Ruan pra
        // sempre. Resultado medido hoje: o Ruan relogou 5 contas, a nossa sonda
        // confirmou as 5 vivas pela API do proprio Flow, e os 5 motores continuavam
        // fora da roda porque o pool.json ainda dizia `login_wall` de meia hora antes.
        //
        // A sonda e o juiz mais confiavel: pergunta a labs.google/fx/api/auth/session
        // e recebe o e-mail da conta. Se ela viu sessao viva AGORA, o retrato velho do
        // PC nao pode manter o motor parado. Continua valendo tudo que NAO e login:
        // `em_uso_humano`, `desligado_manual` e afins seguem barrando normalmente.
        $sondaViva = ! empty($cfg['sessao_valida'])
            && ! empty($cfg['sessao_vista_em'])
            && (function () use ($cfg) {
                try {
                    return \Illuminate\Support\Carbon::parse($cfg['sessao_vista_em'])
                        ->gt(now()->subSeconds(self::SESSAO_FRESCA_S));
                } catch (\Throwable $e) { return false; }
            })();

        $status = $cfg['pc_status'] ?? null;
        if ($sondaViva && is_string($status) && in_array($status, ['login_wall', 'reconnect'], true)) {
            $status = 'ready';   // a sonda viu a conta logada; o retrato do PC esta velho
        }

        if (is_string($status) && $status !== '' && $status !== 'ready') {
            $visto = $cfg['pc_status_em'] ?? null;
            if ($visto) {
                try {
                    if (\Illuminate\Support\Carbon::parse($visto)->gt(now()->subSeconds(self::FROTA_FRESCA_S))) {
                        // SEL-LEASE (12/08): o motivo saia como `pc_login_wall`, e esse
                        // texto viaja DENTRO da mensagem de erro da reserva. O
                        // VideoResilience::classificar() confere a lista CONTEUDO ANTES
                        // da CAPACIDADE e "login_wall" esta nela -> "nenhum motor livre"
                        // era classificado como "o Google recusou" e o video do cliente
                        // virava `failed` DEFINITIVO, sem retry, so porque a frota
                        // estava cheia com alguem deslogado. Mesmo estado, nome que nao
                        // mente pro classificador.
                        $limpo = preg_replace('/[^a-z_]/', '', strtolower($status));
                        if ($limpo === 'login_wall') { $limpo = 'semlogin'; }
                        return 'pc_' . $limpo;
                    }
                } catch (\Throwable $e) { /* data ilegivel -> fail-open */ }
            }
        }

        return null;
    }

    /**
     * SEL-ENGRENAGEM (12/08) — este motor gera de graca?
     *
     * "Veo 3.1 - Lite [Lower Priority]" custa 0 credito (medido: painel do Flow
     * mostra "A geracao vai usar 0 creditos" e conta com 7 creditos gerou video real
     * sem debitar). Quando e esse o modelo, saldo baixo NAO significa incapaz de
     * gerar -- e por isso o guarda de saldo nao se aplica.
     */
    /**
     * SEL-ECOSSISTEMA (12/08) — QUAL CONTA GOOGLE E ESTA, DE VERDADE?
     *
     * Ordem de confianca, e ela importa:
     *   1. `sessao_email` — o e-mail que a SONDA leu em labs.google/fx/api/auth/session
     *      abrindo a sessao daquele motor. E o unico que pergunta pra fonte.
     *   2. `conta_email` / `account_email` — o que ESTA ESCRITO no cadastro. Hoje
     *      esta comprovadamente errado em 5 dos 11 motores (os cofres de sessao foram
     *      gravados trocados), entao so vale quando a sonda ainda nao passou.
     *
     * Devolve null quando nao da pra saber — e nesse caso nao se trava conta nenhuma
     * (fail-open: nunca inventar exclusao em cima de palpite).
     */
    private function contaReal(array $cfg): ?string
    {
        foreach (['sessao_email', 'conta_email', 'account_email'] as $chave) {
            $v = $cfg[$chave] ?? null;
            if (is_string($v) && filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($v));
            }
        }

        return null;
    }

    private function modoGratis(array $cfg): bool
    {
        $modelo = strtolower((string) ($cfg['veo_model'] ?? config('services.veo.model') ?? ''));
        if ($modelo === '') {
            return false;
        }
        foreach (self::MODELOS_SEM_CUSTO as $marca) {
            if (str_contains($modelo, $marca)) {
                return true;
            }
        }
        return false;
    }

    // -- SEL-LEASE: lease com batimento, devolucao garantida e fencing ---------

    /**
     * Diario do lease, em arquivo proprio.
     *
     * O `.env` daqui roda com LOG_LEVEL=error: todo Log::info/Log::warning deste
     * arquivo e DESCARTADO. Justamente os eventos que provam que a rede de
     * seguranca funcionou -- "pulei motor sem sessao", "devolvi trava orfa",
     * "barrei um zumbi" -- nao sao erro, e sem este arquivo nao existe como
     * mostrar depois que aconteceram. Mesmo racional do resiliencia-video.log.
     */
    public static function diario(string $evento, array $dados = []): void
    {
        try {
            @file_put_contents(
                storage_path('logs/lease-video.log'),
                '[' . now()->toDateTimeString() . '] ' . $evento . ' '
                . json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // diario nunca pode derrubar geracao de video
        }
    }

    /**
     * Conexao Redis crua ONDE OS LOCKS REALMENTE MORAM.
     *
     * Pegadinha ja medida e documentada em reconcilia-reservas.php (12/08): o
     * `cache.stores.redis` usa connection=cache mas **lock_connection** separada,
     * e as duas apontam pra BANCOS Redis diferentes. Ler na conexao errada devolve
     * "nao existe" com o lock na mao -- o que transformaria o zelador em algo que
     * rouba motor de quem esta trabalhando. Conexao vem do lock_connection;
     * prefixo vem do proprio store (o prefixo do database o cliente ja poe).
     */
    private function redisCru()
    {
        return \Illuminate\Support\Facades\Redis::connection(
            config('cache.stores.redis.lock_connection') ?: 'default'
        );
    }

    /** Prefixo do cache store -- o mesmo que o Lock do Laravel usa. */
    private function prefixoCache(): string
    {
        try {
            $store = Cache::store('redis')->getStore();
            if (method_exists($store, 'getPrefix')) {
                return (string) $store->getPrefix();
            }
        } catch (\Throwable $e) {
            // cai no config
        }

        return (string) config('cache.prefix');
    }

    private function chaveLock(int $engineId): string
    {
        return $this->prefixoCache() . 'ai_engine_lock:' . $engineId;
    }

    private function chaveLease(int $engineId): string
    {
        return $this->prefixoCache() . 'ai_engine_lease:' . $engineId;
    }

    /**
     * Contador de GERACAO do motor. Nunca expira: e ele que diz "o dono mudou".
     * E o token de fencing -- quem tem geracao velha nao escreve resultado.
     */
    private function chaveGeracao(int $engineId): string
    {
        return $this->prefixoCache() . 'ai_engine_geracao:' . $engineId;
    }

    /**
     * Abre o lease do motor recem-reservado e devolve o numero da geracao.
     *
     * O numero fica no mapa estatico deste processo (self::$geracaoPorMotor) --
     * e por isso que o adapter consegue bater e o job consegue perguntar "ainda
     * sou eu?" sem escrever nada no model.
     */
    private function abrirLease(AiEngine $engine, string $kind): int
    {
        $ger = 0;
        try {
            $r   = $this->redisCru();
            $ger = (int) $r->incr($this->chaveGeracao((int) $engine->id));
            $r->setex($this->chaveLease((int) $engine->id), self::LEASE_TTL, json_encode([
                'ger'       => $ger,
                'kind'      => $kind,
                'pid'       => getmypid(),
                'host'      => gethostname(),
                'aberto_em' => time(),
                'batida_em' => time(),
            ]));
        } catch (\Throwable $e) {
            // Fail-open: sem Redis o comportamento volta a ser o de antes (so lock).
            Log::warning('[SEL-LEASE][AiEnginePool] nao consegui abrir lease', [
                'engine_id' => $engine->id, 'err' => mb_substr($e->getMessage(), 0, 160),
            ]);
        }
        self::$geracaoPorMotor[(int) $engine->id]   = $ger;
        self::$aberturaPorMotor[(int) $engine->id] = time();

        return $ger;
    }

    /**
     * BATIMENTO. Chamado pelo adapter enquanto o render anda (a cada
     * LEASE_BATIDA_S). Renova o lease E o lock: enquanto ha progresso de verdade,
     * o motor nao pode ser considerado livre nem pelo relogio do lock.
     *
     * Devolve false quando a batida NAO vale -- ou seja, quando a geracao mudou e
     * quem esta batendo ja e zumbi. O chamador pode usar isso pra abortar cedo.
     */
    public function heartbeatEngine($engine): bool
    {
        $id  = is_object($engine) ? (int) $engine->id : (int) $engine;
        $ger = (int) (self::$geracaoPorMotor[$id] ?? 0);

        try {
            $r = $this->redisCru();

            if ($ger > 0) {
                $atual = (int) $r->get($this->chaveGeracao($id));
                if ($atual > 0 && $atual !== $ger) {
                    Log::warning('[SEL-LEASE][AiEnginePool] batida recusada: o motor ja tem outro dono', [
                        'engine_id' => $id, 'minha_geracao' => $ger, 'geracao_atual' => $atual,
                    ]);
                    return false;
                }
            }

            $r->setex($this->chaveLease($id), self::LEASE_TTL, json_encode([
                'ger'       => $ger,
                'pid'       => getmypid(),
                'host'      => gethostname(),
                // preservado pra o release conseguir dizer POR QUANTO TEMPO o motor
                // ficou seguro -- e essa a medida de "voltou na hora" vs "voltou no TTL".
                'aberto_em' => self::$aberturaPorMotor[$id] ?? time(),
                'batida_em' => time(),
            ]));
            // Render legitimo pode passar dos 600s do lock (o teto do adapter no modo
            // gratis e 1500s). Enquanto bate, o lock acompanha.
            $r->expire($this->chaveLock($id), self::LOCK_TTL);

            return true;
        } catch (\Throwable $e) {
            return true; // fail-open: problema de Redis nao pode matar render vivo
        }
    }

    /**
     * FENCING. "Este resultado ainda e meu pra escrever?"
     *
     * Compara a geracao que este processo recebeu na reserva com a geracao ATUAL
     * do motor. Se alguem reservou depois de mim, eu sou zumbi: meu mp4 e de uma
     * sessao que ja nao me pertence e escrever por cima atropela o dono novo.
     *
     * De proposito NAO olha a existencia do lease: lease vencido sozinho nao prova
     * atropelo. So a geracao TER AVANCADO prova. Sem isso, qualquer soluco de
     * batimento jogaria fora video bom -- o oposto do que este ticket quer.
     */
    public function leaseNossa($engine): bool
    {
        $id  = is_object($engine) ? (int) $engine->id : (int) $engine;
        $ger = (int) (self::$geracaoPorMotor[$id] ?? 0);
        if ($ger <= 0) {
            return true; // reserva feita antes do lease (ou sem Redis) -> fail-open
        }

        try {
            $atual = (int) $this->redisCru()->get($this->chaveGeracao($id));
            if ($atual <= 0) {
                return true;
            }
            if ($atual !== $ger) {
                self::diario('ZUMBI_BARRADO', [
                    'engine_id'     => $id,
                    'minha_geracao' => $ger,
                    'geracao_atual' => $atual,
                    'nota'          => 'processo perdeu o lease e outro job assumiu o motor; resultado NAO foi escrito',
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * ZELADOR (1 motor). Trava sem batimento = dono morto -> devolve na hora.
     *
     * Tres guardas pra NUNCA tomar motor de quem esta trabalhando:
     *   1. so mexe se a trava existir;
     *   2. so mexe se ja existir contador de geracao pro motor -- ou seja, se este
     *      motor ja foi reservado DEPOIS deste deploy. Render que comecou com o
     *      codigo antigo (sem lease) segue protegido ate terminar;
     *   3. so mexe se a trava nao acabou de ser tomada (TTL quase cheio), pra nao
     *      cair na janela de microssegundos entre pegar o lock e abrir o lease.
     *
     * Devolve tambem a CONTABILIDADE: o reserved_count do dia fica pendurado no
     * mesmo evento que vazou a trava, e e ele que faz motor vivo parecer cheio.
     */
    private function devolverSeOrfao(int $engineId, string $origem): ?string
    {
        try {
            $r    = $this->redisCru();
            $lock = $this->chaveLock($engineId);

            if (! $r->exists($lock)) {
                return null;
            }
            if (! $r->exists($this->chaveGeracao($engineId))) {
                return null; // motor nunca passou pelo lease -> nao e assunto meu
            }
            if ($r->exists($this->chaveLease($engineId))) {
                return null; // batendo -> vivo
            }
            $ttl = (int) $r->ttl($lock);
            if ($ttl > self::LOCK_TTL - 30) {
                return null; // acabou de ser tomada, lease chega em seguida
            }

            $r->del($lock);

            DB::table('ai_engine_usage')
                ->where('engine_id', $engineId)
                ->where('date', now()->utc()->toDateString())
                ->update([
                    'reserved_count' => DB::raw('GREATEST(CAST(reserved_count AS SIGNED) - 1, 0)'),
                    'updated_at'     => now(),
                ]);

            Log::warning('[SEL-LEASE][AiEnginePool] trava ORFA devolvida (dono parou de bater)', [
                'engine_id'   => $engineId,
                'origem'      => $origem,
                'ttl_restante'=> $ttl,
                'nota'        => 'sem isto o motor ficaria preso ate ' . self::LOCK_TTL . 's',
            ]);
            self::diario('TRAVA_ORFA_DEVOLVIDA', [
                'engine_id'      => $engineId,
                'origem'         => $origem,
                'ttl_que_sobrava'=> $ttl,
                'nota'           => 'dono parou de bater; sem o lease isto so soltaria em ' . $ttl . 's',
            ]);

            return 'orfa_devolvida';
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ZELADOR (frota inteira). Chamado pelo comando agendado `video:lease-guardiao`.
     * Devolve a lista de motores que voltaram pro pool.
     */
    public function zelarLeases(string $origem = 'zelador'): array
    {
        $devolvidos = [];
        foreach (AiEngine::where('tool_type', 'video')->pluck('id') as $id) {
            if ($this->devolverSeOrfao((int) $id, $origem)) {
                $devolvidos[] = (int) $id;
            }
        }

        return $devolvidos;
    }

    /**
     * SEL-456: Libera o lock e decrementa reserved_count, incrementa generated_count.
     *
     * DEVE ser chamado no finally do job que chamou reserveEngine.
     *
     * @param int  $engineId  ID do engine reservado
     * @param bool $success   true = geracao concluida (incrementa generated_count)
     *                        false = falhou (so decrementa reserved_count)
     */
    public function releaseEngine($engine, bool $success = true): void
    {
        // SEL 10/08: aceita o OBJETO do engine (carrega o proprio lock em _pool_lock)
        // ou o id cru. Release dono-seguro via objeto; por id, forca a liberacao.
        $engineId = is_object($engine) ? (int) $engine->id : (int) $engine;
        $today = now()->utc()->toDateString();

        // SEL-UNSIGNED-ESTOURA (14/08) — a guarda GREATEST(reserved_count - 1, 0)
        // PARECE certa e nao protege nada: reserved_count e int(10) UNSIGNED, entao
        // o MySQL avalia a SUBTRACAO primeiro e 0-1 ja estoura com SQLSTATE[22003]
        // antes do GREATEST existir. Quando estourava, a devolucao do motor morria
        // por excecao: o motor ficava reservado pra sempre e o pool secava com a
        // fila cheia. Provado no proprio banco:
        //   GREATEST(cast(0 as unsigned) - 1, 0)              -> SQLSTATE[22003]
        //   GREATEST(CAST(cast(0 as unsigned) AS SIGNED)-1,0) -> 0
        // O CAST pra SIGNED tem que vir ANTES da conta. Corrigido nos 3 pontos.
        if ($success) {
            DB::table('ai_engine_usage')
                ->where('engine_id', $engineId)
                ->where('date', $today)
                ->update([
                    'reserved_count'  => DB::raw('GREATEST(CAST(reserved_count AS SIGNED) - 1, 0)'),
                    'generated_count' => DB::raw('generated_count + 1'),
                    'updated_at'      => now(),
                ]);
        } else {
            DB::table('ai_engine_usage')
                ->where('engine_id', $engineId)
                ->where('date', $today)
                ->update([
                    'reserved_count' => DB::raw('GREATEST(CAST(reserved_count AS SIGNED) - 1, 0)'),
                    'updated_at'     => now(),
                ]);
        }

        // Liberar lock Redis -- SEL 10/08 (fix vazamento de lock): solta o PROPRIO
        // objeto de lock (dono-seguro: release() vira no-op se o lock ja expirou e outro
        // job o repegou -> NUNCA mata lock alheio). So com id (sem objeto) forceRelease
        // garante a liberacao. O bug antigo (restoreLock com token VAZIO) nunca soltava,
        // deixando o lock preso ate o TTL (600s) e travando o clipe/geracao seguinte.
        $lockKey = 'ai_engine_lock:' . $engineId;
        try {
            if (is_object($engine) && isset($engine->_pool_lock) && $engine->_pool_lock) {
                $engine->_pool_lock->release();
            } else {
                $this->lockStore()->restoreLock($lockKey, '')->forceRelease();
            }
        } catch (\Throwable $e) {
            try { $this->lockStore()->restoreLock($lockKey, '')->forceRelease(); } catch (\Throwable $e2) {}
            Log::warning('[SEL-456][AiEnginePool] release via objeto falhou, forcei liberacao', [
                'engine_id' => $engineId,
                'err'       => $e->getMessage(),
            ]);
        }

        // SEL-ECOSSISTEMA: devolve a trava da CONTA na mesma hora que a do motor.
        // Se ela vazasse, a conta inteira (que pode servir mais de um motor) ficaria
        // presa ate o TTL de 600s — pior do que o bug do lock de motor de 10/08,
        // porque prenderia varios motores de uma vez.
        try {
            if (is_object($engine) && isset($engine->_pool_conta_lock) && $engine->_pool_conta_lock) {
                $engine->_pool_conta_lock->release();
                $engine->_pool_conta_lock = null;
            } elseif (is_object($engine) && ! empty($engine->_pool_conta)) {
                $this->lockStore()->restoreLock('ai_conta_lock:' . $engine->_pool_conta, '')->forceRelease();
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-ECOSSISTEMA][AiEnginePool] nao consegui devolver a trava de conta', [
                'engine_id' => $engineId,
                'conta'     => is_object($engine) ? ($engine->_pool_conta ?? null) : null,
                'err'       => mb_substr($e->getMessage(), 0, 140),
            ]);
        }

        // SEL-LEASE: devolucao EXPLICITA do lease. O lock ja foi solto acima; o lease
        // some junto pra ninguem confundir "trava velha" com "dono vivo". Apaga so o
        // lease -- o contador de GERACAO nunca e apagado, senao o fencing perderia a
        // memoria de quem foi dono antes.
        $segurouPor = null;
        try {
            $r     = $this->redisCru();
            $bruto = $r->get($this->chaveLease($engineId));
            if ($bruto) {
                $l = json_decode((string) $bruto, true);
                if (is_array($l) && ! empty($l['aberto_em'])) {
                    $segurouPor = max(0, time() - (int) $l['aberto_em']);
                }
            }
            $r->del($this->chaveLease($engineId));
        } catch (\Throwable $e) {
            // lease vence sozinho em LEASE_TTL; nao vale derrubar o release por isso
        }
        $segurouPor = $segurouPor ?? (isset(self::$aberturaPorMotor[$engineId])
            ? max(0, time() - self::$aberturaPorMotor[$engineId])
            : null);
        unset(self::$geracaoPorMotor[$engineId], self::$aberturaPorMotor[$engineId]);

        // Prova de que o motor voltou AGORA, e nao quando o TTL de 600s vencesse.
        self::diario($success ? 'DEVOLVIDO_APOS_SUCESSO' : 'DEVOLVIDO_APOS_FALHA', [
            'engine_id'    => $engineId,
            'segurou_por_s'=> $segurouPor,
            'ttl_do_lock'  => self::LOCK_TTL,
            'nota'         => 'devolucao explicita: o motor volta pro pool na hora, sem esperar o TTL',
        ]);

        Log::info('[SEL-456][AiEnginePool] engine liberado', [
            'engine_id' => $engineId,
            'success'   => $success,
            'lease'     => 'fechado',
        ]);
    }

    /**
     * Quota diaria resolvida para um engine:
     *   1. config_json['quota_per_day'] (override explicito)
     *   2. config_json['quota_type'] => ultra=50 / free=5
     *   3. default = 10
     */
    private function resolveQuota(AiEngine $engine): int
    {
        $cfg = $engine->config_json ?? [];

        // SEL-DIVIDA (13/08) — `!empty()` engolia o valor 0 CALADO.
        //
        // A engine 14 tem `quota_per_day: 0` no banco com um `quota_motivo` que diz,
        // por escrito, que a intencao era 500/dia. Como `!empty(0)` e false, ela caia
        // no `quota_type: default` e recebia 10 — 50x menos que o configurado, sem uma
        // linha de log. Um numero explicito e invalido nao pode virar palpite em
        // silencio: ou vale, ou aparece.
        if (array_key_exists('quota_per_day', $cfg) && $cfg['quota_per_day'] !== null) {
            $v = $cfg['quota_per_day'];
            if (is_numeric($v) && (int) $v > 0) {
                return (int) $v;
            }
            Log::warning('[SEL-DIVIDA][AiEnginePool] quota_per_day invalido no config_json, caindo no palpite', [
                'engine_id' => $engine->id,
                'engine'    => $engine->name,
                'valor'     => $v,
                'nota'      => 'valor precisa ser numerico e > 0; corrija o config_json',
            ]);
        }

        $type = $cfg['quota_type'] ?? 'default';
        if (! array_key_exists($type, self::QUOTA_DEFAULTS)) {
            Log::warning('[SEL-DIVIDA][AiEnginePool] quota_type desconhecido, usando default', [
                'engine_id' => $engine->id,
                'engine'    => $engine->name,
                'quota_type' => $type,
                'conhecidos' => array_keys(self::QUOTA_DEFAULTS),
            ]);
            $type = 'default';
        }

        Log::info('[SEL-DIVIDA][AiEnginePool] engine sem quota explicita, usando palpite nao medido', [
            'engine_id' => $engine->id,
            'engine'    => $engine->name,
            'quota_type' => $type,
            'quota'     => self::QUOTA_DEFAULTS[$type],
        ]);

        return self::QUOTA_DEFAULTS[$type];
    }

    /**
     * Cache store com suporte a lock atomico.
     * Usa Redis (fallback: file store do Laravel, que NAO e atomico entre processos).
     * SEL-456: lock DEVE ser Redis para funcionar com multiplos workers supervisor.
     */
    private function lockStore()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            Log::warning('[SEL-456][AiEnginePool] Redis indisponivel, lock nao sera atomico', [
                'err' => $e->getMessage(),
            ]);
            return Cache::store();
        }
    }

    // -- Fluent interface ------------------------------------------------------

    /**
     * Define o tipo de tool para o proximo dispatch.
     */
    public function for(string $toolType): static
    {
        $clone           = clone $this;
        $clone->toolType = $toolType;
        return $clone;
    }

    // -- Video -----------------------------------------------------------------

    /**
     * Gera video tentando engines video em ordem de prioridade.
     * Interface identica ao VideoEnginePool::generate() anterior.
     *
     * NOTA: este metodo NAO usa reserveEngine/releaseEngine.
     * O KlingBrowserGenerateJob chama reserveEngine ANTES de despachar,
     * passando o engine_id reservado. Chamar reserveEngine aqui criaria
     * double-lock no mesmo engine.
     */
    public function generate(string $taskId, string $kind, array $payload): array
    {
        $engines = AiEngine::availableFor('video');

        if ($engines->isEmpty()) {
            throw new \RuntimeException('no_video_engines_available: nenhum engine video ativo no pool');
        }

        $lastError = null;

        foreach ($engines as $engine) {
            // AUTO-CURA host-probe (Fase 1 item 3): pula motor REMOTO cujo host (PC)
            // esta fora, sem gastar uma tentativa nele. Cacheado ~10s. Aditivo.
            $engCfg = is_array($engine->config_json) ? $engine->config_json
                    : (json_decode($engine->config_json ?? '{}', true) ?: []);
            // SEL-ENGRENAGEM: mesmo portao de frota do reserveEngine -- motor desligado
            // de proposito / deslogado / login_wall no PC nao entra nem por este caminho.
            if ($motivo = $this->estadoDaFrota($engine, $engCfg)) {
                Log::warning('[SEL-ENGRENAGEM][AiEnginePool/video] motor fora da frota, pulando', [
                    'task_id' => $taskId, 'engine' => $engine->name, 'motivo' => $motivo,
                ]);
                continue;
            }
            if (!empty($engCfg['remote']) && !$this->remoteHostHealthy($engCfg['remote'])) {
                Log::warning('[AUTO-CURA][AiEnginePool/video] host remoto fora, pulando motor', [
                    'task_id' => $taskId, 'engine' => $engine->name,
                ]);
                continue;
            }

            Log::info('[SEL-429][AiEnginePool/video] tentando engine', [
                'task_id'  => $taskId,
                'engine'   => $engine->name,
                'provider' => $engine->provider,
                'priority' => $engine->priority,
            ]);

            try {
                $adapter = $this->makeVideoAdapter($engine);
                $result  = $adapter->generate($taskId, $kind, $payload);
                $engine->recordSuccess();

                Log::info('[SEL-429][AiEnginePool/video] engine OK', [
                    'task_id' => $taskId,
                    'engine'  => $engine->name,
                    'took_s'  => $result['took_s'] ?? null,
                ]);

                return $result;

            } catch (DicloakNotConfiguredException $e) {
                $lastError = $e->getMessage();
                Log::info('[SEL-429][AiEnginePool/video] engine nao configurado (pulando)', [
                    'engine' => $engine->name,
                    'reason' => $e->getMessage(),
                ]);

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $engine->recordFailure(mb_substr($e->getMessage(), 0, 500));
                Log::warning('[SEL-429][AiEnginePool/video] engine falhou, tentando proximo', [
                    'task_id' => $taskId,
                    'engine'  => $engine->name,
                    'err'     => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        throw new \RuntimeException('all_video_engines_failed: ' . mb_substr((string) $lastError, 0, 300));
    }

    // -- LLM -------------------------------------------------------------------

    /**
     * Envia mensagens para o LLM tentando engines em ordem de prioridade.
     */
    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string
    {
        $engines = AiEngine::availableFor('llm');

        // SEL-LLM-PLANO-B (14/08) — plano B que nao funciona e pior que nao ter plano B.
        // O `availableFor` esconde motor em cooldown. Hoje o Gemini #13 estourou cota (429)
        // e o #15 levou um 503 passageiro: os dois entraram em cooldown ao mesmo tempo, a
        // lista veio VAZIA e o codigo caiu aqui, no OpenAI — que esta com a chave vazia
        // neste backend. Resultado: `openai_not_configured` e o cliente com roteiro-tampao,
        // com um motor perfeitamente vivo esperando do lado.
        // Agora: se o OpenAI nao estiver configurado, em vez de morrer eu tento os motores
        // de novo IGNORANDO o cooldown. Motor em cooldown pode estar de pe; chave vazia
        // nunca vai funcionar. Na duvida, tenta quem tem chance.
        if ($engines->isEmpty()) {
            $emCooldown = AiEngine::where('tool_type', 'llm')->where('is_active', true)
                ->orderBy('priority')->get();

            $openaiTemChave = trim((string) config('services.openai.key', env('OPENAI_API_KEY', ''))) !== '';

            if (! $openaiTemChave && $emCooldown->isNotEmpty()) {
                Log::error('[SEL-LLM-PLANO-B] todos os motores de texto em cooldown e OpenAI sem chave — tentando mesmo assim', [
                    'motores' => $emCooldown->pluck('name')->all(),
                ]);
                $engines = $emCooldown;
            } else {
                Log::warning('[SEL-429][AiEnginePool/llm] sem engines LLM ativos, usando OpenAI direto como emergencia');
                return (new OpenAiDirectAdapter())->chat($messages, $temperature, $maxTokens);
            }
        }

        $lastError = null;

        foreach ($engines as $engine) {
            try {
                $adapter = $this->makeLlmAdapter($engine);
                $result  = $adapter->chat($messages, $temperature, $maxTokens);
                $engine->recordSuccess();
                return $result;

            } catch (DicloakNotConfiguredException $e) {
                $lastError = $e->getMessage();
                Log::info('[SEL-429][AiEnginePool/llm] engine nao configurado (pulando)', [
                    'engine' => $engine->name,
                    'reason' => mb_substr($e->getMessage(), 0, 200),
                ]);

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $engine->recordFailure(mb_substr($e->getMessage(), 0, 500));
                Log::warning('[SEL-429][AiEnginePool/llm] engine falhou', [
                    'engine' => $engine->name,
                    'err'    => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        throw new \RuntimeException('all_llm_engines_failed: ' . mb_substr((string) $lastError, 0, 300));
    }

    // -- Image -----------------------------------------------------------------

    /**
     * Gera imagem tentando engines image em ordem de prioridade.
     */
    public function generateImage(string $prompt, array $refImages = [], string $size = '1024x1024'): array
    {
        $engines = AiEngine::availableFor('image');

        if ($engines->isEmpty()) {
            throw new \RuntimeException('no_image_engines_available: configure ao menos 1 engine image ativo');
        }

        $lastError = null;

        foreach ($engines as $engine) {
            try {
                $adapter = $this->makeImageAdapter($engine);
                $result  = $adapter->generate($prompt, $refImages, $size);
                $engine->recordSuccess();
                return $result;

            } catch (DicloakNotConfiguredException $e) {
                $lastError = $e->getMessage();
                Log::info('[SEL-429][AiEnginePool/image] engine nao configurado (pulando)', ['engine' => $engine->name]);

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $engine->recordFailure(mb_substr($e->getMessage(), 0, 500));
                Log::warning('[SEL-429][AiEnginePool/image] engine falhou', ['engine' => $engine->name, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        throw new \RuntimeException('all_image_engines_failed: ' . mb_substr((string) $lastError, 0, 300));
    }

    // -- Scraping --------------------------------------------------------------

    /**
     * Executa scraping tentando engines scraping em ordem de prioridade.
     */
    public function scrape(string $url, string $sessionKey = 'default', array $options = []): array
    {
        $engines = AiEngine::availableFor('scraping');

        if ($engines->isEmpty()) {
            throw new \RuntimeException('no_scraping_engines_available: configure ao menos 1 engine scraping ativo');
        }

        $lastError = null;

        foreach ($engines as $engine) {
            try {
                $adapter = $this->makeScrapingAdapter($engine);
                $result  = $adapter->scrape($url, $sessionKey, $options);
                $engine->recordSuccess();
                return $result;

            } catch (DicloakNotConfiguredException $e) {
                $lastError = $e->getMessage();
                Log::info('[SEL-429][AiEnginePool/scraping] engine nao configurado (pulando)', ['engine' => $engine->name]);

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $engine->recordFailure(mb_substr($e->getMessage(), 0, 500));
                Log::warning('[SEL-429][AiEnginePool/scraping] engine falhou', ['engine' => $engine->name, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        throw new \RuntimeException('all_scraping_engines_failed: ' . mb_substr((string) $lastError, 0, 300));
    }

    // -- AUTO-CURA host-probe (Fase 1 item 3) ----------------------------------

    /**
     * O HOST remoto (PC do Ruan, via tunel reverso localhost:2200) esta de pe?
     *
     * Sonda `ssh ... echo ok` com timeout CURTO, resultado CACHEADO ~10s (o watchdog
     * video:pc-autocura tambem prima este mesmo cache). Quando o PC cai (reboot /
     * tunel fora), TODOS os motores remotos que apontam pro mesmo host sao pulados de
     * uma vez pelo reserveEngine/generate -> o pool cai no motor LOCAL de reserva na
     * hora, em vez de esperar 3 retries morrerem num cadaver.
     *
     * Fail-open: se a PROPRIA sondagem nao pode rodar (ssh ausente / erro de spawn),
     * retorna true -- NAO derruba os remotos as cegas (o recordFailure do job cobre o
     * caso raro). So um exit!=0 limpo (connection refused / timeout) marca DOWN.
     *
     * Publico de proposito: o comando de auto-cura reaproveita o mesmo probe/cache.
     */
    public function remoteHostHealthy(array $remote): bool
    {
        $host = $remote['host'] ?? 'localhost';
        $port = (string) ($remote['port'] ?? 2200);
        $user = $remote['user'] ?? 'ruan';
        $key  = $remote['ssh_key'] ?? '/home/api.seller.global/.ssh/pc-render';
        $cacheKey = 'ai_remote_host_up:' . $user . '@' . $host . ':' . $port;

        return (bool) Cache::remember($cacheKey, 10, function () use ($host, $port, $user, $key) {
            try {
                $p = new \Symfony\Component\Process\Process([
                    'ssh', '-i', $key, '-p', $port,
                    '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120',
                    '-o', 'ConnectTimeout=5', '-o', 'ServerAliveInterval=3',
                    $user . '@' . $host, 'echo ok',
                ]);
                $p->setTimeout(9);
                $p->run();
                return $p->isSuccessful() && str_contains($p->getOutput(), 'ok');
            } catch (\Throwable $e) {
                // spawn/erro ambiguo -> NAO pula os remotos (fail-open)
                return true;
            }
        });
    }

    // -- Adapter factories -----------------------------------------------------

    private function makeVideoAdapter(AiEngine $engine): \App\Contracts\VideoGeneratorContract
    {
        return match ($engine->provider) {
            'dicloak-flow' => new DicloakFlowAdapter($engine),
            'mac-flow'     => new MacFlowVideoAdapter($engine),
            'dicloak-flow-ui' => new DicloakFlowUIAdapter($engine),
            default        => throw new \RuntimeException("video adapter desconhecido: {$engine->provider}"),
        };
    }

    private function makeLlmAdapter(AiEngine $engine): \App\Contracts\LlmContract
    {
        return match ($engine->provider) {
            'dicloak-gpt-ui' => new DicloakGptUIAdapter($engine),
            'dicloak-gpt'   => new DicloakGptAdapter($engine),
            'gemini-direct' => new GeminiDirectAdapter($engine),
            'openai-direct' => new OpenAiDirectAdapter($engine),
            default         => throw new \RuntimeException("llm adapter desconhecido: {$engine->provider}"),
        };
    }

    private function makeImageAdapter(AiEngine $engine): \App\Contracts\ImageGeneratorContract
    {
        return match ($engine->provider) {
            'dicloak-image' => new DicloakImageAdapter($engine),
            'mac-flow-image' => new MacFlowImageAdapter($engine),
            default         => throw new \RuntimeException("image adapter desconhecido: {$engine->provider}"),
        };
    }

    private function makeScrapingAdapter(AiEngine $engine): \App\Contracts\ScrapingContract
    {
        return match ($engine->provider) {
            'dicloak-scraping' => new DicloakScrapingAdapter($engine),
            default            => throw new \RuntimeException("scraping adapter desconhecido: {$engine->provider}"),
        };
    }
}
