<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMedia extends Model
{
    protected $fillable = [
        'product_id',
        'product_variation_id',
        'type',
        'path',
        'url',
        'original_url',
        'local_path',
        'external_id',
        'position',
        'is_cover',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * Resolve a URL da midia para sempre retornar URL absoluta e acessivel.
     *
     * Historico de mudancas:
     *   MUL-FIX-3 / 2026-06-23: accessor criado com fallback para original_url
     *     porque o backfill BunnyCDN estava em andamento e arquivos podiam estar 404.
     *   MUL-IMG-1 / 2026-06-27: backfill BunnyCDN concluido (17.000+ arquivos, todos 200).
     *     Fallback removido — original_url aponta para /storage/ legado que pode nao existir.
     *     URLs bunny agora sao retornadas diretamente sem verificacao de fallback.
     *     306 imagens com path /storage/ migradas para BunnyCDN diretamente no banco.
     *     9 imagens fornecefy.io (produtos 1 e 4589) removidas/corrigidas.
     *
     * Regra atual (simplificada):
     *   - Path relativo → APP_URL + path (casos residuais)
     *   - URL BunnyCDN com bug /storage/products/ → reescrita para /products/
     *   - URL absoluta (http/https) → retorna como esta
     *
     * @param string|null $value
     * @return string|null
     */
    public function getUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Path relativo — resolve usando APP_URL como base (casos residuais)
        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            $base = rtrim(config('app.url'), '/');
            $path = '/' . ltrim($value, '/');
            return $base . $path;
        }

        // Saneamento legacy: URLs Bunny salvas com /storage/products/ erradamente
        // (bug pre-FOR-034). A Storage Zone tem path /products/, sem /storage/.
        // Reescreve transparente para nao quebrar fotos antigas no banco.
        $cdnBase = rtrim(config('services.bunnycdn.pull_zone_url', ''), '/');
        if ($cdnBase && str_starts_with($value, $cdnBase . '/storage/products/')) {
            return $cdnBase . '/products/' . substr($value, strlen($cdnBase . '/storage/products/'));
        }

        // URL ja absoluta (BunnyCDN ou externa) — retorna como esta.
        // Fallback para original_url foi removido em MUL-IMG-1/2026-06-27:
        // backfill BunnyCDN concluido, todos arquivos 200 OK no CDN.
        return $value;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }
}
