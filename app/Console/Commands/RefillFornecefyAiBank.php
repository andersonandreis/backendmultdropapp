<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FOR-088b: feeder continuo do banco de titulo/descricao (product_ai_cache).
 *
 * A reserva e consumivel: cada clique do cliente em "gerar titulo/descricao"
 * marca used_at numa linha e "gasta" ela (AIProductContentService::serveFromBank).
 * Produtos ativos podem esvaziar a reserva com o tempo, e produto novo entra sem
 * nenhuma linha. Este comando garante >= --target linhas disponiveis (title
 * preenchido, used_at NULL, marketplace=mercadolivre) por produto ativo.
 *
 * Geracao 100% DETERMINISTICA POR TEMPLATE a partir de product.name/brand/category
 * -- NUNCA chama OpenAI nem nenhuma API paga (a chave do Fornecefy esta desativada
 * de proposito desde o FOR-088, ver AIProductContentService::AI_CONTENT_BANK_ONLY).
 *
 * Guard Schema::hasTable: o repo hubai-plataforma e compartilhado por 4 backends
 * (hubai, multdrop, mestoredrop, fornecefy) e so o fornecefy usa este comando.
 */
class RefillFornecefyAiBank extends Command
{
    protected $signature = 'bank:refill-fornecefy
        {--target=12 : reserva minima (linhas com title nao vazio e used_at null) por produto}
        {--limit=0 : limite de produtos processados nesta execucao (0 = sem limite)}';

    protected $description = 'FOR-088b: reabastece o banco de titulo/descricao (product_ai_cache) pra ML/Shopee sem usar OpenAI';

    /** Palavras sem valor de busca — puladas na extracao de keywords (mantidas se o nome for muito curto). */
    private const STOPWORDS = ['de', 'da', 'do', 'das', 'dos', 'com', 'para', 'pra', 'e', 'em', 'a', 'o', 'as', 'os', 'um', 'uma', 'no', 'na'];

    /** Sinonimos leves pra variar o titulo sem inventar atributo que o produto nao tem. */
    private const SYNONYMS = [
        'kit' => 'conjunto',
        'conjunto' => 'kit',
        'suporte' => 'base',
        'controle' => 'controlador',
        'capa' => 'protetor',
        'bolsa' => 'necessaire',
        'porta' => 'organizador',
        'adaptador' => 'conversor',
        'extensor' => 'extensao',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('product_ai_cache')) {
            $this->info('Tabela product_ai_cache nao existe neste backend — nada a fazer.');

            return self::SUCCESS;
        }

        $target = max(1, (int) $this->option('target'));
        $limit = (int) $this->option('limit');

        $rows = DB::table('products as p')
            ->leftJoin('product_ai_cache as c', function ($join) {
                $join->on('c.sku_codigo', '=', 'p.sku')
                    ->where('c.marketplace', '=', 'mercadolivre')
                    ->whereNull('c.used_at')
                    ->where('c.title', '<>', '');
            })
            ->where('p.is_active', 1)
            ->whereNotNull('p.sku')
            ->where('p.sku', '<>', '')
            ->groupBy('p.id')
            ->havingRaw('COUNT(c.id) < ?', [$target])
            ->select('p.id', DB::raw('COUNT(c.id) as reserva'))
            ->get();

        if ($limit > 0) {
            $rows = $rows->take($limit);
        }

        $this->info("Produtos ativos com reserva < {$target}: {$rows->count()}");

        $totalInserted = 0;
        $productsTouched = 0;

        foreach ($rows as $row) {
            $product = Product::query()->with('category')->find($row->id);
            if (! $product || ! $product->sku) {
                continue;
            }

            $need = $target - (int) $row->reserva;
            if ($need <= 0) {
                continue;
            }

            $pairs = $this->generatePairs($product, $need);
            if ($pairs === []) {
                continue;
            }

            $now = now();
            $insert = [];
            foreach ($pairs as $pair) {
                $insert[] = [
                    'sku_codigo' => (string) $product->sku,
                    'marketplace' => 'mercadolivre',
                    'title' => $pair['title'],
                    'description' => $pair['description'],
                    'suggested_category' => $pair['category'],
                    'attributes' => null,
                    'generated_at' => $now,
                    'used_at' => null,
                ];
            }

            DB::table('product_ai_cache')->insert($insert);
            $totalInserted += count($insert);
            $productsTouched++;
        }

        $this->info("Inseridas {$totalInserted} linhas em {$productsTouched} produtos.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{title:string, description:string, category:?string}>
     */
    private function generatePairs(Product $product, int $count): array
    {
        $name = trim((string) ($product->name ?: 'Produto'));
        $brandRaw = trim((string) ($product->brand ?: ''));
        $brand = in_array(mb_strtolower($brandRaw), ['', 'generico', 'genérico', 'sem marca', 's/marca', 'n/a'], true)
            ? null
            : $brandRaw;
        $category = $product->category?->name;

        $tokens = $this->tokenize($name);
        if ($tokens === []) {
            $tokens = [$name !== '' ? $name : 'Produto'];
        }

        $pairs = [];
        $seen = [];

        // Espaco de combinacoes = rotacao de tokens x toggle de sinonimo x sufixo marca/categoria.
        // Tenta ate 8x o alvo pra achar variantes unicas antes de completar com fallback.
        for ($i = 0; count($pairs) < $count && $i < $count * 8; $i++) {
            $title = $this->buildTitle($tokens, $brand, $category, $i);
            $key = mb_strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $pairs[] = [
                'title' => $title,
                'description' => $this->buildDescription($name, $tokens, $brand, $category, $i),
                'category' => $category,
            ];
        }

        // Nome muito curto (1-2 palavras, sem marca/categoria) pode nao gerar variantes
        // unicas suficientes. Completa reaproveitando a melhor variante ja gerada —
        // mesmo piso de qualidade da degradacao graciosa que o service ja faz (nome cru).
        while (count($pairs) < $count && $pairs !== []) {
            $pairs[] = $pairs[0];
        }

        return $pairs;
    }

    private function tokenize(string $name): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $words = preg_split('/\s+/u', trim((string) $clean)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        if (count($words) <= 3) {
            return $words;
        }

        $filtered = array_values(array_filter(
            $words,
            fn ($w) => ! in_array(mb_strtolower($w), self::STOPWORDS, true)
        ));

        return $filtered !== [] ? $filtered : $words;
    }

    /**
     * Reordena as keywords de forma deterministica (mesmo variant sempre gera a mesma
     * ordem) mas nao ciclica — rotacao pura so da $n ordens distintas mesmo tentando
     * $n variantes; um shuffle (Fisher-Yates com LCG seedado pelo variant) cobre um
     * espaco muito maior (ate $n!), essencial pra nomes curtos de 4-7 palavras sem
     * marca/categoria (maioria do catalogo) nao repetirem titulo antes de bater 12.
     */
    private function permute(array $tokens, int $variant): array
    {
        $n = count($tokens);
        if ($n <= 1) {
            return $tokens;
        }

        $order = range(0, $n - 1);
        $seed = ($variant + 1) * 2654435761;
        for ($k = $n - 1; $k > 0; $k--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $j = $seed % ($k + 1);
            [$order[$k], $order[$j]] = [$order[$j], $order[$k]];
        }

        return array_map(fn ($idx) => $tokens[$idx], $order);
    }

    private function buildTitle(array $tokens, ?string $brand, ?string $category, int $variant): string
    {
        $rotated = $this->permute($tokens, $variant);

        // Sinonimo leve em 1 palavra a cada 2 variantes — mantem o termo de busca real do produto.
        if ($variant % 2 === 1) {
            foreach ($rotated as $idx => $word) {
                $syn = self::SYNONYMS[mb_strtolower($word)] ?? null;
                if ($syn !== null) {
                    $rotated[$idx] = $syn;
                    break;
                }
            }
        }

        $parts = $rotated;

        $mod = $variant % 4;
        if ($mod === 1 && $category) {
            $parts[] = $category;
        } elseif ($mod === 2 && $brand) {
            $parts[] = $brand;
        } elseif ($mod === 3 && $brand) {
            $parts[] = $brand;
            if ($category) {
                $parts[] = $category;
            }
        }

        $title = preg_replace('/\s+/', ' ', trim(implode(' ', $parts)));

        return mb_substr($title, 0, 60);
    }

    private function buildDescription(string $name, array $tokens, ?string $brand, ?string $category, int $variant): string
    {
        $keyword = $tokens[$variant % max(1, count($tokens))] ?? $name;

        $intros = [
            "{$name} chega para facilitar o seu dia a dia com praticidade e qualidade.",
            "Conheca o {$name}, pensado para quem busca praticidade no dia a dia.",
            "{$name}: a escolha certa para quem valoriza qualidade e praticidade.",
        ];
        $intro = $intros[$variant % count($intros)];

        $bullets = array_values(array_filter([
            "• Produto: {$name}",
            $brand ? "• Marca: {$brand}" : null,
            $category ? "• Categoria: {$category}" : null,
            "• Destaque de busca: {$keyword}",
            '• Praticidade no uso do dia a dia',
            '• Qualidade verificada antes do envio',
        ]));

        $ctas = [
            'Garanta o seu agora e aproveite a praticidade que esse produto oferece.',
            'Adicione ao carrinho e receba com toda a seguranca e agilidade.',
            'Nao perca tempo, garanta ja o seu e sinta a diferenca no dia a dia.',
        ];
        $cta = $ctas[$variant % count($ctas)];

        $text = $intro."\n\n".implode("\n", $bullets)."\n\n".$cta;

        return mb_substr($text, 0, 3000);
    }
}
