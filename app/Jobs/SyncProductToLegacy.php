<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\GoolhubBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Empurra um Product do hubaiapp pro sku_pai legado via bridge produto_upsert.php.
 *
 * Retry com backoff exponencial (30s, 90s, 270s) para sobreviver a deadlocks MySQL
 * no legado. Apenas campos alterados são enviados no UPDATE (reduz janela de lock).
 * Anti-loop garantido via _source=hubaiapp na bridge.
 */
class SyncProductToLegacy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $productId, public string $action = 'upsert', public ?int $legacyId = null)
    {
        $this->onQueue('legacy'); // NOV-199: carga do legado nao compete com a fila default
    }

    /**
     * Backoff exponencial: 30s, 90s, 270s.
     * Absorve deadlocks temporários sem sobrecarregar o legado.
     */
    public function backoff(): array
    {
        return [30, 90, 270];
    }

    /**
     * Resolve dinamicamente o id_deposito legado a partir do supplier.
     * Substitui o mapa hardcoded SUPPLIER_TO_DEPOSITO (NOV-161): cada backend
     * (hubai/multdrop/fornecefy/mestoredrop) tem supplier_id próprio e o
     * legacy_id correto vem do banco.
     */
    private static function getIdDeposito(int $supplierId): ?int
    {
        return Supplier::where('id', $supplierId)->value('legacy_id');
    }

    public function handle(GoolhubBridgeService $bridge): void
    {
        if ($this->action === 'delete') {
            $legacyId = $this->legacyId;
            if (!$legacyId) {
                Log::info("[Sync→Legado] delete sem legacy_sku_pai_id");
                return;
            }
            $res = $bridge->deleteSkuPai($legacyId);
            if (!$res['success']) {
                $this->handleBridgeError('delete', ['legacy_id' => $legacyId], $res);
                return;
            }
            Log::info("[Sync→Legado] deletado sku_pai={$legacyId}");
            return;
        }

        $p = Product::with('media')->find($this->productId);
        if (!$p) {
            Log::warning("[Sync→Legado] Product {$this->productId} sumiu — descartando job");
            return;
        }

        $idDeposito = self::getIdDeposito($p->supplier_id);
        if (!$idDeposito) {
            Log::warning("[Sync→Legado] supplier {$p->supplier_id} sem legacy_id no banco — descartando job");
            return;
        }

        $attrs = is_array($p->attributes) ? $p->attributes : [];

        // Dimensoes: novo em cm, legado em metros
        $largura     = $p->width_cm  !== null ? round((float) $p->width_cm  / 100, 2) : null;
        $altura      = $p->height_cm !== null ? round((float) $p->height_cm / 100, 2) : null;
        $comprimento = $p->length_cm !== null ? round((float) $p->length_cm / 100, 2) : null;

        $imagens = $p->media
            ->sortBy(fn($m) => $m->is_cover ? -1 : (int)($m->position ?? 999))
            ->map(fn($m) => ['img' => $this->absoluteImageUrl($m->url), 'posicao' => (int)($m->position ?? 0)])
            ->values()->all();

        // Payload mínimo: somente campos com valor definido para reduzir colunas no UPDATE
        // e diminuir a janela de lock no legado (prevenção de deadlock).
        $payload = array_filter([
            '_source'          => 'hubaiapp',
            'sku'              => $p->sku,
            'id_deposito'      => $idDeposito,
            'descricao'        => $p->name,
            'desc_produto'     => $p->description,
            'custo'            => $p->cost,
            'custo_curso'      => $p->price,
            'estoque'          => $p->effectiveStock,
            'ncm'              => $attrs['ncm']     ?? null,
            'origem'           => $attrs['origem']  ?? null,
            'ean'              => $p->ean,
            'marca'            => $p->brand,
            'garantia'         => $p->warranty_months !== null ? (string) $p->warranty_months : null,
            'peso'             => $p->weight_kg,
            'largura'          => $largura,
            'altura'           => $altura,
            'comprimento'      => $comprimento,
            'id_categoria_sku' => $p->category_id,
            'video_url'        => $p->video_url,
            'img'              => $this->absoluteImageUrl(optional($p->media->firstWhere('is_cover', 1) ?? $p->media->first())->url),
            'imagens'          => $imagens ?: null,
        ], fn($v) => $v !== null);

        // Garante que _source e campos obrigatorios sempre presentes
        $payload['_source']     = 'hubaiapp';
        $payload['sku']         = $p->sku;
        $payload['id_deposito'] = $idDeposito;

        $res = $bridge->upsertSkuPai($payload);
        if (!$res['success']) {
            $this->handleBridgeError('upsert', ['product_id' => $this->productId, 'sku' => $p->sku], $res);
            return;
        }

        $idSkuPai = $res['data']['id'] ?? null;
        if ($idSkuPai && $p->legacy_sku_pai_id !== $idSkuPai) {
            $p->forceFill(['legacy_sku_pai_id' => $idSkuPai])->saveQuietly();
        }

        Log::info("[Sync→Legado] Product {$this->productId} (sku={$p->sku}) sincronizado → sku_pai_id={$idSkuPai}");
    }

    /**
     * Trata falha da bridge. Se for deadlock detectado na resposta, relança
     * a exception para acionar o retry com backoff. Caso contrário, loga e falha.
     */
    private function handleBridgeError(string $op, array $context, array $res): void
    {
        $error = $res['error'] ?? '?';
        $isDeadlock = stripos($error, 'deadlock') !== false || stripos($error, '1213') !== false;

        Log::error("[Sync→Legado] bridge {$op} falhou", array_merge($context, ['res' => $res]));

        throw new \RuntimeException("Bridge {$op} falhou" . ($isDeadlock ? ' [deadlock]' : '') . ": {$error}");
    }

    /**
     * Garante URL absoluta de imagem. Imagens salvas com path relativo
     * (/storage/products/...) precisam do prefixo do domínio antes de
     * serem enviadas ao legado.
     */
    private function absoluteImageUrl(?string $url): string
    {
        if (!$url) return '';
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return 'https://api.multdrop.app/' . ltrim($url, '/');
    }
}
