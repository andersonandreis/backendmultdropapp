<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\AIProductContentService;
use App\Services\ProductContentBankService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-XXX (04/08): reabastecimento em lote do banco de conteudo (product_content_bank).
 *
 * Cobre o catalogo NA ORDEM DA PAGINA DO CLIENTE (TenantApi/V1/ProductController@index
 * usa Product::orderBy('id') asc) -- guarda um ponteiro (product_content_bank_cursors)
 * de ate onde ja cobriu, pra proxima rodada continuar sem pular nem repetir produto.
 * Ao chegar no fim do catalogo, volta pro inicio (id > 0) -- catalogo cresce e produtos
 * antigos podem voltar a precisar de reposicao com o tempo.
 *
 * Anti-duplicata: SEMPRE contra o proprio banco daquele produto (nao entre produtos
 * diferentes) -- injeta os titulos ja existentes no prompt e reconfirma via
 * ProductContentBankService::collidesWithBank() antes de gravar.
 *
 * Motor plugavel (--engine): 'api' (Gemini/OpenAI via AIProductContentService::chat(),
 * 1 produto por chamada) ou 'browser' (gemini.google.com via Playwright/xvfb-run,
 * VARIOS produtos numa conversa so -- molde: browser-worker/generate_video.js do Kling).
 *
 * Uso:
 *   php artisan content-bank:replenish --dry-run --target=20
 *   php artisan content-bank:replenish --target=1000 --engine=api
 *   php artisan content-bank:replenish --target=1000 --engine=browser --browser-batch-size=10
 */
class ReplenishProductContentBankCommand extends Command
{
    protected $signature = 'content-bank:replenish
        {--target=1000 : Quantidade de novos registros (titulo+descricao+bullets) a gerar nesta rodada}
        {--low-water=10 : So repoe produto cujo banco disponivel esta abaixo deste numero}
        {--top-up=5 : Quantas variacoes gerar por produto elegivel nesta passada}
        {--max-uses=5 : Teto de reuso de cada registro novo}
        {--engine=api : Motor de geracao: api (Gemini/OpenAI via HTTP) ou browser (gemini.google.com via Playwright)}
        {--browser-batch-size=8 : (engine=browser) quantos produtos por conversa/chamada do navegador}
        {--dry-run : Nao chama IA nem grava -- so lista quem seria processado}';

    protected $description = 'SEL-XXX: reabastece o banco de titulo/descricao em lote, cobrindo o catalogo em ordem (id asc), anti-dup contra o proprio banco';

    private const CURSOR_NAME = 'catalog_id_asc';

    public function handle(ProductContentBankService $bank, AIProductContentService $ai): int
    {
        $target    = (int) $this->option('target');
        $lowWater  = (int) $this->option('low-water');
        $topUp     = (int) $this->option('top-up');
        $maxUses   = (int) $this->option('max-uses');
        $engine    = $this->option('engine');
        $browserBatchSize = (int) $this->option('browser-batch-size');
        $dryRun    = (bool) $this->option('dry-run');
        $sourceBatch = 'batch-' . now()->format('YmdHis');

        if (! in_array($engine, ['api', 'browser'], true)) {
            $this->error("--engine invalido: {$engine} (use api|browser)");
            return self::FAILURE;
        }

        if (! $dryRun && $engine === 'api' && ! $ai->hasApiKey()) {
            $this->error('Nenhuma chave de IA configurada (AI_TEXT_PROVIDER) -- abortando.');
            return self::FAILURE;
        }

        $cursor = DB::table('product_content_bank_cursors')->where('name', self::CURSOR_NAME)->first();
        $lastId = $cursor->last_product_id ?? 0;

        $this->info("[ContentBank] replenish iniciado | engine={$engine} | target={$target} | low_water={$lowWater} | top_up={$topUp} | cursor(id>{$lastId}) | dry_run=" . ($dryRun ? 'SIM' : 'NAO'));

        $generated = 0;
        $productsChecked = 0;
        $wrapped = false;
        $browserQueue = []; // fila de produtos elegiveis aguardando ir num lote de navegador
        $browserEngineDead = false; // circuit-breaker: sessao invalida derruba o comando inteiro, nao fica retentando o catalogo todo

        while ($generated < $target && ! $browserEngineDead) {
            $product = Product::where('is_active', true)->where('id', '>', $lastId)->orderBy('id')->first();

            if (! $product) {
                if ($engine === 'browser' && ! empty($browserQueue)) {
                    [$added, $fatal] = $this->flushBrowserQueue($bank, $browserQueue, $sourceBatch, $maxUses, $dryRun);
                    $generated += $added;
                    $browserQueue = [];
                    if ($fatal) { $browserEngineDead = true; continue; }
                }
                if ($wrapped) {
                    $this->info('[ContentBank] catalogo inteiro percorrido nesta rodada sem preencher o target -- parando.');
                    break;
                }
                $lastId = 0; // wrap-around
                $wrapped = true;
                continue;
            }

            $lastId = $product->id;
            $productsChecked++;

            $productKey = $bank->productKeyFor($product);
            $available  = $bank->availableCount($productKey);

            if ($available >= $lowWater) {
                continue; // pool cheio, pula pro proximo produto do catalogo
            }

            $needed = min($topUp, $target - $generated);
            if ($needed <= 0) {
                break;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Produto #{$product->id} ({$product->sku}) key={$productKey} disponivel={$available} -- geraria {$needed}");
                $generated += $needed;
                continue;
            }

            if ($engine === 'browser') {
                // engine browser: 1 variacao por produto por chamada (o worker gera
                // title+desc+bullets numa tacada); pra pedir topUp>1 do mesmo produto
                // ele reentra na fila em rodadas seguintes deste while.
                $browserQueue[] = $product;
                if (count($browserQueue) >= $browserBatchSize) {
                    [$added, $fatal] = $this->flushBrowserQueue($bank, $browserQueue, $sourceBatch, $maxUses, $dryRun);
                    $generated += $added;
                    $browserQueue = [];
                    if ($fatal) { $browserEngineDead = true; }
                }
                continue;
            }

            // engine api (default) -- 1 produto por chamada, sincrono
            $existingTitles = $bank->allTitlesForKey($productKey);
            for ($i = 0; $i < $needed; $i++) {
                try {
                    $entry = $this->generateOneViaApi($ai, $product, $existingTitles);
                } catch (\Throwable $e) {
                    Log::error('[ContentBank] falha ao gerar variacao (api)', [
                        'product_id' => $product->id, 'error' => $e->getMessage(),
                    ]);
                    $this->warn("  Produto #{$product->id}: falha na geracao -- {$e->getMessage()}");
                    break;
                }

                $inserted = $bank->store($productKey, $entry['title'], $entry['description'], $entry['bullet_points'], $sourceBatch, $maxUses);

                if ($inserted) {
                    $existingTitles[] = $entry['title'];
                    $generated++;
                    $this->line("  Produto #{$product->id}: +1 no banco ({$entry['title']})");
                } else {
                    $this->line("  Produto #{$product->id}: descartado por colisao com o banco");
                }
            }
        }

        // sobrou fila de navegador sem fechar lote cheio (bateu o target no meio)
        if ($engine === 'browser' && ! empty($browserQueue) && $generated < $target && ! $browserEngineDead) {
            [$added, $fatal] = $this->flushBrowserQueue($bank, $browserQueue, $sourceBatch, $maxUses, $dryRun);
            $generated += $added;
            $browserEngineDead = $browserEngineDead || $fatal;
        }

        // Circuit-breaker disparado: nao avanca cursor (nada de real foi coberto
        // de forma confiavel) e sai com erro pra quem chamou saber que precisa
        // corrigir a sessao antes de tentar de novo.
        if ($browserEngineDead) {
            $this->error('[ContentBank] motor browser com sessao invalida/login wall -- abortando o restante do lote (nao ficou retentando o catalogo inteiro). Reexporte a sessao do Gemini antes de rodar de novo.');
            return self::FAILURE;
        }

        if (! $dryRun) {
            DB::table('product_content_bank_cursors')->updateOrInsert(
                ['name' => self::CURSOR_NAME],
                ['last_product_id' => $lastId, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->info("[ContentBank] Concluido | engine={$engine} | gerados={$generated} | produtos_verificados={$productsChecked} | cursor_final=id>{$lastId} | batch={$sourceBatch}" . ($dryRun ? ' (DRY-RUN -- cursor NAO avancado)' : ''));

        return self::SUCCESS;
    }

    /** Erros que significam "motor inteiro esta morto" -- nao adianta tentar o proximo lote, aborta o comando. */
    private const FATAL_BROWSER_ERRORS = [
        'gemini_session_invalid_or_login_wall',
        'session_file_missing',
    ];

    /**
     * Manda o lote acumulado pro worker do navegador (gemini.google.com),
     * distribui os resultados de volta pra cada produto por id, e grava no
     * banco com o mesmo anti-dup-vs-banco de sempre.
     *
     * @param Product[] $products
     * @return array{0:int,1:bool} [quantos registros novos gravados, se foi erro FATAL (aborta o comando)]
     */
    private function flushBrowserQueue(ProductContentBankService $bank, array $products, string $sourceBatch, int $maxUses, bool $dryRun): array
    {
        if (empty($products)) {
            return [0, false];
        }

        if ($dryRun) {
            $this->line('  [DRY-RUN] lote navegador: ' . implode(', ', array_map(fn ($p) => "#{$p->id}", $products)));
            return [count($products), false];
        }

        $payload = [
            'batch_id' => $sourceBatch . '-' . uniqid(),
            'products' => array_map(fn (Product $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'description' => $p->description,
                'brand'       => $p->brand,
                'model'       => $p->model,
            ], $products),
        ];

        $workerJs  = env('GEMINI_BROWSER_WORKER_JS', '/home/api.seller.global/browser-worker/gemini_content_worker.js');
        $workerDir = env('GEMINI_BROWSER_WORKER_DIR', '/home/api.seller.global/browser-worker');

        $cmd = ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', $workerJs];
        $proc = new Process($cmd, $workerDir, [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'GEMINI_SESSION_PATH' => env('GEMINI_BROWSER_SESSION_PATH', '/home/api.seller.global/storage/kling-browser/google-session.json'),
        ]);
        $proc->setTimeout(180);
        $proc->setInput(json_encode($payload));

        try {
            $proc->run();
        } catch (\Throwable $e) {
            Log::error('[ContentBank] worker browser falhou ao rodar', ['error' => $e->getMessage()]);
            $this->error("  Lote navegador falhou: {$e->getMessage()}");
            return [0, false];
        }

        $stdout = trim($proc->getOutput());
        $lines = preg_split('/\r?\n/', $stdout);
        $jsonLine = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (strlen($line) > 0 && $line[0] === '{' && substr($line, -1) === '}') {
                $jsonLine = $line;
                break;
            }
        }
        $decoded = $jsonLine ? json_decode($jsonLine, true) : null;

        if (! $decoded || empty($decoded['ok'])) {
            $err = $decoded['error'] ?? trim($proc->getErrorOutput() ?: $stdout);
            Log::error('[ContentBank] worker browser retornou erro', ['error' => $err]);
            $this->error("  Lote navegador retornou erro: " . mb_substr((string) $err, 0, 300));
            $fatal = in_array($err, self::FATAL_BROWSER_ERRORS, true);
            return [0, $fatal];
        }

        $byId = [];
        foreach ($products as $p) {
            $byId[$p->id] = $p;
        }

        $inserted = 0;
        foreach ($decoded['results'] as $r) {
            $productId = $r['id'] ?? null;
            if (! $productId || ! isset($byId[$productId])) {
                continue;
            }
            $product = $byId[$productId];
            $productKey = $bank->productKeyFor($product);

            $title = mb_substr(trim($r['title'] ?? ''), 0, 60);
            $description = mb_substr(trim($r['description'] ?? ''), 0, 3000);
            $bullets = is_array($r['bullets'] ?? null) ? array_slice($r['bullets'], 0, 5) : null;

            if ($title === '') {
                continue;
            }

            $ok = $bank->store($productKey, $title, $description ?: null, $bullets, $sourceBatch, $maxUses);
            if ($ok) {
                $inserted++;
                $this->line("  [browser] Produto #{$productId}: +1 no banco ({$title})");
            } else {
                $this->line("  [browser] Produto #{$productId}: descartado por colisao com o banco");
            }
        }

        return [$inserted, false];
    }

    /**
     * Gera UMA variacao (titulo+descricao+bullets) pro produto via API HTTP
     * (Gemini/OpenAI conforme AI_TEXT_PROVIDER), injetando os titulos ja
     * existentes no banco como instrucao anti-duplicata.
     */
    private function generateOneViaApi(AIProductContentService $ai, Product $product, array $existingTitles): array
    {
        $avoid = empty($existingTitles) ? '' : (' NÃO repita nenhum destes títulos já usados para este produto: '
            . implode(' | ', array_slice($existingTitles, 0, 15)) . '.');

        $context = implode("\n", array_filter([
            "Nome do produto: {$product->name}",
            $product->description ? "Descrição atual: {$product->description}" : null,
            $product->brand ? "Marca: {$product->brand}" : null,
            $product->model ? "Modelo: {$product->model}" : null,
        ]));

        $titleSystem = 'Você é especialista em SEO para Mercado Livre e Shopee no Brasil. '
            . 'Gere títulos de anúncio altamente clicáveis. '
            . 'REGRAS: máximo 60 caracteres, sem emojis, sem maiúsculas excessivas, '
            . 'inclua marca/modelo quando relevante, use palavras-chave de busca reais.' . $avoid;
        $titleUser = "Crie um título de anúncio para o produto abaixo. Retorne APENAS o título, sem explicações.\n\n{$context}";
        $title = mb_substr(trim($ai->chat($titleSystem, $titleUser, 60)), 0, 60);

        $descSystem = 'Você é copywriter especialista em e-commerce brasileiro. '
            . 'Escreva descrições persuasivas em TEXTO PURO (sem HTML, sem markdown). '
            . 'Use quebras de linha para parágrafos e • para bullet points. '
            . 'REGRAS: máximo 3000 caracteres, foco em benefícios, CTA sutil no final.';
        $descUser = "Crie uma descrição em texto puro para o produto abaixo. Retorne APENAS o texto.\n\n{$context}";
        $description = mb_substr(trim($ai->chat($descSystem, $descUser, 800)), 0, 3000);

        $bulletSystem = 'Você é especialista em comunicação de benefícios de produtos para e-commerce. '
            . 'Gere exatamente 5 bullet points concisos, máx 100 chars cada, um por linha, sem numeração.';
        $bulletUser = "Crie 5 bullet points de benefícios para o produto abaixo.\n\n{$context}";
        $bulletRaw = trim($ai->chat($bulletSystem, $bulletUser, 300));
        $bullets = array_slice(array_values(array_filter(array_map('trim', explode("\n", $bulletRaw)))), 0, 5);

        return ['title' => $title, 'description' => $description, 'bullet_points' => $bullets];
    }
}
