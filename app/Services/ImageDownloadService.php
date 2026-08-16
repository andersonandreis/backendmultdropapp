<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Baixa imagens externas e armazena no disk publico local (api.fornecefy.io/storage).
 *
 * Path padrao: products/{supplier_id}/{product_id}/{md5(url)}.{ext}
 * URL publica:  https://api.fornecefy.io/storage/products/.../*.jpg
 *
 * Idempotente: re-baixar o mesmo URL no mesmo supplier+product reaproveita
 * o arquivo ja salvo (mesmo md5).
 *
 * NUNCA lanca: erro = retorna null + log. Importers nao podem quebrar
 * por uma imagem ruim.
 */
class ImageDownloadService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Maximo aceitavel por imagem (10MB — alinhado ao upload manual do ProductController). */
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    private const EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** Magic bytes para deteccao real do formato (defesa contra content-type forjado). */
    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89PNG\r\n\x1A\n"],
        'image/webp' => ['RIFF'], // mais checagem feita abaixo (WEBP em offset 8)
        'image/gif'  => ['GIF87a', 'GIF89a'],
    ];

    /**
     * Baixa, valida e armazena uma imagem externa no disk publico.
     *
     * @return array{path: string, url: string}|null
     *   path = caminho relativo no disk (products/1/123/abc.jpg)
     *   url  = URL publico final (https://api.fornecefy.io/storage/products/...)
     *   ou null em caso de qualquer falha (URL invalida, host caido, tipo invalido, etc).
     */
    public function downloadAndStoreImage(string $externalUrl, int $supplierId, ?int $productId = null): ?array
    {
        // SSRF defense: so HTTP/HTTPS
        if (!preg_match('#^https?://#i', $externalUrl)) {
            return null;
        }

        // Bloqueia hosts privados/loopback (defesa em profundidade contra fornecedor malicioso)
        if (!$this->isPublicUrl($externalUrl)) {
            Log::warning('[ImageDownload] URL bloqueada (host privado/local)', ['url' => $externalUrl]);
            return null;
        }

        $folder = sprintf('products/%d/%s', $supplierId, $productId !== null ? (string) $productId : 'unassigned');

        try {
            $response = Http::withOptions([
                'allow_redirects' => [
                    'max'             => 3,
                    'strict'          => true,
                    'referer'         => false,
                    'protocols'       => ['http', 'https'],
                    'track_redirects' => false,
                ],
                'timeout'         => 20,
                'connect_timeout' => 10,
            ])
            ->withHeaders([
                'User-Agent' => 'FornecefyImageBot/1.0 (+https://api.fornecefy.io)',
            ])
            ->get($externalUrl);

            if (!$response->successful()) {
                Log::info('[ImageDownload] HTTP nao-2xx', ['url' => $externalUrl, 'status' => $response->status()]);
                return null;
            }

            $contentType = strtolower(trim(explode(';', $response->header('Content-Type') ?? '')[0]));
            $body        = $response->body();
            $size        = strlen($body);

            // Magic bytes sao mais confiaveis que content-type reportado por servidores externos.
            // Goolhub serve JPEG/WebP como application/octet-stream.
            // Multdropbr serve WebP como image/jpeg.
            // Regra: detectar pelo corpo e usar o tipo real (magic beats header).
            $detected = $this->detectMimeFromBytes($body);
            if ($detected !== null) {
                // Usa sempre o tipo detectado pelos magic bytes
                $contentType = $detected;
            } elseif (!in_array($contentType, self::ALLOWED_MIME_TYPES, true)) {
                Log::info('[ImageDownload] content-type invalido e magic nao reconhecido', [
                    'url' => $externalUrl, 'type' => $contentType,
                ]);
                return null;
            }

            if ($size > self::MAX_SIZE_BYTES) {
                Log::info('[ImageDownload] arquivo grande demais', ['url' => $externalUrl, 'bytes' => $size]);
                return null;
            }

            if ($size < 100) {
                Log::info('[ImageDownload] arquivo suspeito (muito pequeno)', ['url' => $externalUrl, 'bytes' => $size]);
                return null;
            }

            // contentType ja foi normalizado via detectMimeFromBytes acima (magic > header).
            // EXTENSION_MAP so aceita tipos permitidos, o que garante que nao vaza nenhum outro formato.
            $extension = self::EXTENSION_MAP[$contentType] ?? null;
            if ($extension === null) {
                Log::info('[ImageDownload] tipo nao mapeado apos deteccao', ['url' => $externalUrl, 'type' => $contentType]);
                return null;
            }
            $filename  = md5($externalUrl) . '.' . $extension;
            $relPath   = $folder . '/' . $filename;

            $disk = Storage::disk('public');

            // Idempotencia: ja existe -> retorna o que tinha.
            if ($disk->exists($relPath)) {
                return [
                    'path' => $relPath,
                    'url'  => $disk->url($relPath),
                ];
            }

            $disk->put($relPath, $body);

            return [
                'path' => $relPath,
                'url'  => $disk->url($relPath),
            ];
        } catch (\Throwable $e) {
            Log::warning('[ImageDownload] excecao', [
                'url' => $externalUrl,
                'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Atalho usado por importers: tenta baixar. Se conseguir, devolve URL local.
     * Se nao conseguir, devolve URL original (degradacao suave — importer nao quebra).
     */
    public function ensureLocal(string $externalUrl, int $supplierId, ?int $productId = null): array
    {
        // Se ja e URL local desta instalacao, nao baixa.
        $appHost = parse_url(config('app.url', 'https://api.hubai.io'), PHP_URL_HOST) ?? '';
        if ($appHost && stripos($externalUrl, $appHost . '/storage/') !== false) {
            return ['url' => $externalUrl, 'path' => null, 'local' => true];
        }

        // Se ja e URL do BunnyCDN, nao precisa baixar — ja e CDN publico.
        if (stripos($externalUrl, 'b-cdn.net/') !== false || stripos($externalUrl, 'multdrop-images.b-cdn.net') !== false) {
            return ['url' => $externalUrl, 'path' => null, 'local' => true];
        }

        $result = $this->downloadAndStoreImage($externalUrl, $supplierId, $productId);

        if ($result === null) {
            return ['url' => $externalUrl, 'path' => null, 'local' => false];
        }

        return ['url' => $result['url'], 'path' => $result['path'], 'local' => true];
    }

    /**
     * @deprecated Use downloadAndStoreImage() — mantido pra retrocompat com
     * o command `images:download-local` ja existente.
     */
    public function downloadFromUrl(string $url): ?string
    {
        $result = $this->downloadAndStoreImage($url, 0, null);
        return $result['path'] ?? null;
    }

    /**
     * Detecta mime type pelos magic bytes do arquivo.
     * Retorna mime string ou null se nao reconhecido.
     */
    private function detectMimeFromBytes(string $body): ?string
    {
        if (str_starts_with($body, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($body, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) return 'image/gif';
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') return 'image/webp';
        return null;
    }

    private function validateMagicBytes(string $body, string $contentType): bool
    {
        $signatures = self::MAGIC_BYTES[$contentType] ?? [];

        foreach ($signatures as $sig) {
            if (str_starts_with($body, $sig)) {
                // WEBP tem RIFF em 0 e WEBP em 8
                if ($contentType === 'image/webp') {
                    return substr($body, 8, 4) === 'WEBP';
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Bloqueia URLs que apontem para hosts privados/loopback (SSRF defesa).
     */
    private function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!isset($parts['host'])) return false;

        $host = strtolower($parts['host']);

        // Block obvious internal names
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal', '169.254.169.254'];
        if (in_array($host, $blocked, true)) return false;

        // Se for IP, valida que e publico
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // Hostname comum (goolhub.io, app.multdropbr.com etc) — passa.
        return true;
    }
}
