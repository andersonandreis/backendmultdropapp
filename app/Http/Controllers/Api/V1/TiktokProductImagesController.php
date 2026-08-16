<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ScrapeTiktokProductImagesJob;
use App\Services\TikTokMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * SEL-321: Endpoint de imagens multi-fonte de produto TikTok Shop.
 *
 * GET  /v1/insights/tiktok/product-images/{key}
 *   Cascata: kalodata → catálogo → scrape (dispara job se vazio) → devolve lista com origem.
 *
 * POST /v1/insights/tiktok/product-images/{key}/upload
 *   Upload de imagem pelo cliente (source=upload).
 */
class TiktokProductImagesController extends Controller
{
    public function __construct(private TikTokMediaService $media) {}

    /**
     * GET /v1/insights/tiktok/product-images/{key}
     *
     * Resolve cascata de imagens do produto e retorna lista ordenada por quality_score.
     * Dispara ScrapeTiktokProductImagesJob se não há imagens de scrape (listing/review).
     */
    public function index(Request $request, string $key)
    {
        if (! preg_match('/^\d{10,20}$/', $key)) {
            return response()->json(['message' => 'product_key inválido.'], 422);
        }

        // Buscar imagens já persistidas
        $images = DB::table('tiktok_product_images')
            ->where('product_key', $key)
            ->whereNotNull('url_local')
            ->orderByDesc('quality_score')
            ->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'source'        => $r->source,
                'source_label'  => $this->sourceLabel($r->source),
                'url'           => $r->url_local,
                'quality_score' => $r->quality_score,
            ])
            ->values()
            ->toArray();

        // Se não há imagens de listing/review, disparar scrape
        $hasScrape = collect($images)->whereIn('source', ['listing', 'review'])->count() > 0;
        $scrapeQueued = false;

        if (! $hasScrape) {
            $pendingExists = DB::table('tiktok_product_images')
                ->where('product_key', $key)
                ->where('scrape_status', 'pending')
                ->exists();

            // Cooldown: tentativa concluída nas últimas 6h não re-dispara
            $recentAttempt = DB::table('tiktok_product_images')
                ->where('product_key', $key)
                ->where('url_original', '__scrape_queued__')
                ->where('updated_at', '>=', now()->subHours(6))
                ->exists();

            if (! $pendingExists && ! $recentAttempt) {
                // Marcar como pending e disparar job
                DB::table('tiktok_product_images')->updateOrInsert(
                    ['product_key' => $key, 'source' => 'listing', 'url_original' => '__scrape_queued__'],
                    ['scrape_status' => 'pending', 'updated_at' => now(), 'created_at' => now()]
                );
                ScrapeTiktokProductImagesJob::dispatch($key);
                $scrapeQueued = true;
            }
        }

        // Se nenhuma imagem disponível ainda, tentar fonte kalodata em tempo real
        if (empty($images)) {
            $kaImage = $this->resolveKalodataImage($key);
            if ($kaImage) {
                $images[] = [
                    'id'            => null,
                    'source'        => 'kalodata',
                    'source_label'  => 'Catálogo TikTok Shop',
                    'url'           => $kaImage,
                    'quality_score' => 80,
                ];
            }
        }

        return response()->json([
            'product_key'   => $key,
            'images'        => $images,
            'total'         => count($images),
            'scrape_queued' => $scrapeQueued,
            'message'       => $scrapeQueued
                ? 'Buscando mais imagens do produto. Consulte novamente em alguns segundos.'
                : null,
        ]);
    }

    /**
     * POST /v1/insights/tiktok/product-images/{key}/upload
     *
     * Upload de imagem do produto pelo cliente.
     */
    public function upload(Request $request, string $key)
    {
        if (! preg_match('/^\d{10,20}$/', $key)) {
            return response()->json(['message' => 'product_key inválido.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Arquivo inválido. Use JPG, PNG ou WebP até 10MB.'], 422);
        }

        $file     = $request->file('image');
        $hash     = md5_file($file->getPathname());
        $ext      = $file->getClientOriginalExtension() ?: 'jpg';
        $path     = 'tt-media/product-upload-' . $key . '-' . $hash . '.' . $ext;

        Storage::disk('public')->put($path, file_get_contents($file->getPathname()));
        $localUrl = Storage::disk('public')->url($path);

        DB::table('tiktok_product_images')->updateOrInsert(
            ['product_key' => $key, 'source' => 'upload', 'url_original' => $localUrl],
            [
                'url_local'     => $localUrl,
                'quality_score' => 90,
                'scrape_status' => 'done',
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );

        return response()->json([
            'source'  => 'upload',
            'url'     => $localUrl,
            'message' => 'Imagem carregada com sucesso.',
        ], 201);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function resolveKalodataImage(string $key): ?string
    {
        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$key])
            ->orderByDesc('snapshot_date')
            ->first();

        if (! $row) return null;
        $p = json_decode($row->payload, true);
        $imageUrl = $p['image_url'] ?? null;
        if (! $imageUrl) return null;

        // Garantir que é local
        return $this->media->ensureLocal($imageUrl) ?? $imageUrl;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'kalodata' => 'Catálogo TikTok Shop',
            'catalog'  => 'Catálogo',
            'listing'  => 'Anúncio',
            'review'   => 'Avaliação',
            'upload'   => 'Enviado por você',
            default    => 'Outro',
        };
    }
}
