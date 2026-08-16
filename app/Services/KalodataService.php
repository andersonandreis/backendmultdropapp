<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-256 — cliente da API pública Kalodata TikTok Shop BR.
 *
 * Endpoints anônimos (SEM auth, SEM captcha) descobertos em 19/07/2026:
 *   POST https://www.kalodata.com/overview/rank/queryProductTops
 *   POST https://www.kalodata.com/overview/rank/queryCreatorTops
 *   POST https://www.kalodata.com/overview/rank/queryVideoTops
 *   POST https://www.kalodata.com/overview/rank/queryLiveTops
 *   POST https://www.kalodata.com/overview/rank/queryShopTops
 *
 * Headers obrigatórios: content-type, country=BR, currency=USD, language=pt-BR.
 * Body: {startDate, endDate, pageNo, pageSize} — pageSize max efetivo 10.
 *
 * SEL-409 (30/07/2026) — o sync ficou 7 dias trazendo ZERO sem ninguém ver.
 * Causa: a partir de ~23/07 a Cloudflare da kalodata.com passou a responder
 * HTTP 403 (managed challenge) pra todo cliente HTTP simples — curl, Guzzle e
 * urllib caem igual. NÃO é login expirado nem rate-limit: o bloqueio é por
 * fingerprint de cliente. Dentro de um Chromium real, sem nenhuma credencial,
 * os MESMOS endpoints devolvem 200. Por isso existe o modo 'browser', que
 * delega o fetch ao worker Playwright (kalodata_fetch.js).
 *
 * O modo antigo ('http') continua aqui de propósito: se um dia a Cloudflare
 * afrouxar, ele volta a ser o caminho barato — não precisa subir navegador.
 */
class KalodataService
{
    private const BASE = 'https://www.kalodata.com/overview/rank';

    private const HEADERS = [
        'content-type'  => 'application/json',
        'country'       => 'BR',
        'currency'      => 'USD',
        'language'      => 'pt-BR',
        'accept'        => 'application/json, text/plain, */*',
        'user-agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        'referer'       => 'https://www.kalodata.com/explore',
    ];

    public const TYPES = [
        'products' => 'queryProductTops',
        'creators' => 'queryCreatorTops',
        'videos'   => 'queryVideoTops',
        'lives'    => 'queryLiveTops',
        'shops'    => 'queryShopTops',
    ];

    /**
     * Puxa top items de um endpoint, paginando até `$maxPages` (10 items/página).
     * Retorna array plano.
     *
     * @throws \RuntimeException quando o fetch falha de verdade. Antes do SEL-409
     *         esse método engolia o erro e devolvia [], o que fazia o sync
     *         reportar "✅ 0 linhas" por 7 dias seguidos.
     */
    public function fetch(string $type, string $startDate, string $endDate, int $maxPages = 10): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Tipo inválido: $type");
        }

        return config('services.kalodata.fetch_mode') === 'browser'
            ? $this->fetchViaBrowser($type, $startDate, $endDate, $maxPages)
            : $this->fetchViaHttp($type, $startDate, $endDate, $maxPages);
    }

    /**
     * SEL-409: fetch dentro de um Chromium real (mesma stack do worker do Kling).
     * O script pagina sozinho e imprime {"ok":bool,"items":[...]} no stdout;
     * as linhas de diagnóstico vão pro stderr pra não sujar o JSON.
     */
    private function fetchViaBrowser(string $type, string $startDate, string $endDate, int $maxPages): array
    {
        $script = config('services.kalodata.browser_script');
        if (!is_file($script)) {
            throw new \RuntimeException("[SEL-409 Kalodata] worker ausente: $script");
        }

        $payload = json_encode([
            'endpoint'  => self::TYPES[$type],
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'maxPages'  => $maxPages,
            'pageSize'  => 10,
            'country'   => 'BR',
        ], JSON_UNESCAPED_UNICODE);

        $proc = new Process(
            // SEL-KALO-HEADED (14/08): com tela (xvfb) porque o Cloudflare da
            // Kalodata responde 403 pra navegador headless -- medido hoje nos dois
            // modos. -a escolhe um display livre sozinho.
            ['xvfb-run', '-a', 'node', $script, $payload],
            config('services.kalodata.browser_dir'),
            [
                'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ]
        );
        $proc->setTimeout((int) config('services.kalodata.browser_timeout', 420));
        $proc->run();

        $stdout = trim($proc->getOutput());

        // SEL-KALO-XVFB (14/08): o worker manda progresso em stderr e o JSON em
        // stdout -- separacao certa. So que o `xvfb-run` JUNTA os dois na mesma
        // saida, entao o stdout chega assim:
        //     [kalodata_fetch] queryProductTops p1: 10 items (10 novos)
        //     {"ok":true,"items":[...]}
        // e o json_decode do bloco inteiro devolvia null -> "falhou" com os dados
        // JA BAIXADOS na mao. Aqui a gente pega o JSON de dentro da mistura.
        $decoded = $stdout !== '' ? json_decode($stdout, true) : null;
        if (! is_array($decoded) && $stdout !== '') {
            $ini = strpos($stdout, '{');
            if ($ini !== false) {
                $decoded = json_decode(substr($stdout, $ini), true);
            }
        }

        if (!is_array($decoded) || empty($decoded['ok'])) {
            $motivo = $decoded['error']
                ?? (trim($proc->getErrorOutput()) ?: $stdout ?: 'sem saída do worker');
            Log::error("[SEL-409 Kalodata] $type falhou no navegador: " . mb_substr($motivo, 0, 400));
            throw new \RuntimeException("[SEL-409 Kalodata] $type falhou: " . mb_substr($motivo, 0, 200));
        }

        return $decoded['items'] ?? [];
    }

    /**
     * Caminho HTTP direto. Bloqueado pela Cloudflare desde ~23/07/2026 (403),
     * mantido para quando/se o bloqueio cair.
     */
    private function fetchViaHttp(string $type, string $startDate, string $endDate, int $maxPages): array
    {
        $url = self::BASE . '/' . self::TYPES[$type];
        $all = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $resp = Http::withHeaders(self::HEADERS)
                ->timeout(20)
                ->post($url, [
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                    'pageNo'    => $page,
                    'pageSize'  => 10,
                ]);

            // SEL-409: era Log::warning + break. Com LOG_LEVEL=error no .env o
            // warning nunca era gravado — o 403 sumia e o sync dizia "✅ 0 linhas".
            if (!$resp->successful()) {
                $dica = $resp->status() === 403
                    ? ' (403 = challenge Cloudflare; use KALODATA_FETCH_MODE=browser)'
                    : '';
                Log::error("[SEL-256 Kalodata] $type page=$page HTTP {$resp->status()}$dica");
                if ($page === 1) {
                    throw new \RuntimeException("[SEL-256 Kalodata] $type HTTP {$resp->status()}$dica");
                }
                break; // já trouxe algo: entrega o parcial em vez de perder tudo
            }
            $data = $resp->json('data') ?? [];
            if (empty($data)) break;
            $all = array_merge($all, $data);
            usleep(250_000); // 250ms entre requests — respeitoso
        }
        return $all;
    }

    /**
     * Extrai id externo do item (varia por type). Usado como chave de dedup.
     */
    public function extractExternalId(string $type, array $item): ?string
    {
        return match ($type) {
            'products' => $item['id'] ?? null,
            'creators' => $item['handle'] ?? ($item['uid'] ?? null),
            'videos'   => $item['id'] ?? null,
            'lives'    => $item['uid'] ?? ($item['id'] ?? null),
            'shops'    => $item['shop_id'] ?? ($item['id'] ?? null),
            default    => null,
        };
    }
}
