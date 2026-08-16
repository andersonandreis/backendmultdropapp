<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DirectorySupplier;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SEL-048: Endpoints do diretorio de fornecedores (Lista de Fornecedores).
 *
 * GET /api/v1/directory-suppliers           — listagem paginada com filtros
 * GET /api/v1/directory-suppliers/{slug}    — detalhe completo
 *
 * Gate por plano (min_plan_id): DESATIVADO NA PRATICA — todos os registros
 * tem min_plan_id=null, e getUserPlanId() sempre retorna null (Subscription
 * nao tem coluna user_id, so client_id — a query estoura e o catch engole).
 * Nao usar/religar esse mecanismo sem antes corrigir os dois problemas: os
 * IDs de plano (85 Start < 86 Scaling < 87 Pro, mas tiktok_free=89 > 87)
 * fariam o free "ganhar" de planos pagos numa comparacao >=.
 *
 * Gate real (01/08, Ruan "verifica agora e bloqueia"): tier gratuito (sem
 * assinatura paga ativa/trial — tiktok_free R$0, tt_shop_trial_3d R$0, ou
 * sem assinatura nenhuma) so ve 3 fornecedores (sempre pagina 1, nao da pra
 * "passear" as paginas) e nao acessa o detalhe (show). Mesma definicao de
 * "pago" usada em App\Services\Ai\VideoAccessGuard::pode(). Ver isFreeTierAccess().
 */
class DirectorySupplierController extends Controller
{
    /**
     * GET /api/v1/directory-suppliers
     *
     * Query params:
     *   category      string  — filtro por categoria exata
     *   location      string  — filtro por localizacao (LIKE %value%)
     *   verified      bool    — apenas verificados (1/true)
     *   marketplace   string  — filtra por marketplace ex.: shopee
     *   uf            string  — filtro por UF derivada de DDD/location ex.: SP
     *   has_catalog   bool    — apenas quem tem catalog_url
     *   min_order_max string  — (reservado para futuro)
     *   updated_since string  — ISO 8601, ex.: 2026-07-01T00:00:00Z
     *   q             string  — busca fulltext em name e description
     *   sort          string  — name|updated_at (default: name ASC)
     *   per_page      int     — itens por pagina (default 24, max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $userPlanId = $this->getUserPlanId($request);

        // SEL-096 Ruan 20:20: plano supplier_only (id=90) só ve fornecedores
        // que ja foram desbloqueados pelo command suppliers:unlock-weekly.
        // Outros planos (start/scaling/pro/free) veem tudo (sujeitos aos gates ja existentes).
        $user = $request->user();
        $client = $user?->client;
        $sub = $client?->subscriptions()->with('plan:id,slug,price_monthly,price_yearly')->whereIn('status', ['active','trialing'])->latest()->first();
        $planSlug = $sub?->plan?->slug;
        $isFreeTier = $this->isFreeTierAccess($user, $sub);

        $query = DirectorySupplier::active();

        if ($planSlug === 'supplier_only' && $client) {
            $unlockedIds = \DB::table('client_supplier_unlocks')
                ->where('client_id', $client->id)
                ->pluck('directory_supplier_id')
                ->toArray();
            $query->whereIn('id', $unlockedIds ?: [0]);
        }

        // Filtros
        if ($request->filled('category')) {
            $query->inCategory($request->input('category'));
        }

        if ($request->filled('location')) {
            $query->where('location', 'LIKE', '%' . $request->input('location') . '%');
        }

        if ($request->boolean('verified')) {
            $query->verified();
        }

        if ($request->filled('marketplace')) {
            $query->inMarketplace($request->input('marketplace'));
        }

        if ($request->filled('uf')) {
            $uf = strtoupper((string) $request->input('uf'));
            $ids = array_keys(array_filter($this->getUfMap(), fn (string $u) => $u === $uf));
            $query->whereIn('id', $ids ?: [0]);
        }

        if ($request->boolean('has_catalog')) {
            $query->whereNotNull('catalog_url');
        }

        if ($request->filled('updated_since')) {
            try {
                $since = new \DateTime($request->input('updated_since'));
                $query->where('updated_at', '>=', $since->format('Y-m-d H:i:s'));
            } catch (\Exception $e) {
                // data invalida — ignorar filtro
            }
        }

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        // Ordenacao
        $sort = $request->input('sort', 'name');
        match ($sort) {
            'updated_at' => $query->orderByDesc('updated_at'),
            default      => $query->orderBy('name'),
        };

        $perPage = min((int) $request->input('per_page', 24), 100);
        $forcedPage = null;

        // Ruan 01/08 "verifica agora e bloqueia": free (R$0) so ve 3, sempre
        // pagina 1 — forcado aqui (nao so no per_page) pra nao dar pra somar
        // a lista inteira pedindo ?page=2,3,4... 3 em 3.
        if ($isFreeTier) {
            $perPage = 3;
            $forcedPage = 1;
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $forcedPage);

        // Monta payload
        $items = collect($paginated->items())
            ->map(fn (DirectorySupplier $s) => $s->toApiArray($userPlanId, false))
            ->values();

        // Agrega categorias com contagem (para o frontend montar os filtros)
        $categoriesMeta = $this->getCategoriesMeta();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page'     => $paginated->currentPage(),
                'last_page'        => $paginated->lastPage(),
                'per_page'         => $paginated->perPage(),
                'total'            => $paginated->total(),
                'categories'       => $categoriesMeta,
                'ufs'              => $this->getUfsMeta(),
                'free_tier_capped' => $isFreeTier,
            ],
        ]);
    }

    /**
     * GET /api/v1/directory-suppliers/{slug}
     *
     * Ruan 01/08 "verifica agora e bloqueia": detalhe completo (contatos,
     * catalog_url) e exclusivo de quem tem plano pago. Sem isso, dava pra
     * contornar o cap de 3 do index() adivinhando/enumerando slugs.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $supplier = DirectorySupplier::active()
            ->where('slug', $slug)
            ->firstOrFail();

        $user = $request->user();
        $client = $user?->client;
        $sub = $client?->subscriptions()->with('plan:id,slug,price_monthly,price_yearly')->whereIn('status', ['active','trialing'])->latest()->first();

        if ($this->isFreeTierAccess($user, $sub)) {
            return response()->json([
                'error'        => 'upgrade_required',
                'message'      => 'O detalhe completo do fornecedor e exclusivo dos planos pagos. Faca upgrade para desbloquear.',
                'current_tier' => 'free',
            ], 403);
        }

        $userPlanId = $this->getUserPlanId($request);

        return response()->json([
            'data' => $supplier->toApiArray($userPlanId, true),
        ]);
    }

    /**
     * SEL-(novo) 01/08 — Ruan "verifica agora e bloqueia": true quando o
     * cliente nao tem nenhuma assinatura paga ativa/trial. Cobre tiktok_free
     * (R$0), tt_shop_trial_3d (R$0, teste de 3 dias) e "sem assinatura
     * nenhuma". Mesma definicao de "pago" usada em
     * App\Services\Ai\VideoAccessGuard::pode() — qualquer plano com
     * price_monthly>0 OU price_yearly>0 ja libera (tt_shop_monthly R$29,90/mes
     * ja e suficiente pra ver a lista completa; nao precisa ser o Pro R$297,
     * que so e exigido pra GERAR VIDEO no Studio — gate separado).
     *
     * super_admin sempre passa (uso interno/suporte), mesmo sem client.
     */
    private function isFreeTierAccess(?\App\Models\User $user, ?\App\Models\Subscription $sub): bool
    {
        if ($user && $user->role === 'super_admin') {
            return false;
        }

        if (! $sub || ! $sub->plan) {
            return true;
        }

        $pago = ((float) ($sub->plan->price_monthly ?? 0)) > 0
             || ((float) ($sub->plan->price_yearly ?? 0)) > 0;

        return ! $pago;
    }

    // --------------------------------------------------------------- Privados

    /**
     * Retorna o ID do plano ativo do usuario autenticado.
     * Retorna null se o usuario nao tiver assinatura ativa.
     */
    private function getUserPlanId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        try {
            $sub = Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

            return $sub?->plan_id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** DDD -> UF (67 DDDs) para derivar a regiao do fornecedor. */
    private const DDD_UF = [
        '11' => 'SP', '12' => 'SP', '13' => 'SP', '14' => 'SP', '15' => 'SP', '16' => 'SP', '17' => 'SP', '18' => 'SP', '19' => 'SP',
        '21' => 'RJ', '22' => 'RJ', '24' => 'RJ',
        '27' => 'ES', '28' => 'ES',
        '31' => 'MG', '32' => 'MG', '33' => 'MG', '34' => 'MG', '35' => 'MG', '37' => 'MG', '38' => 'MG',
        '41' => 'PR', '42' => 'PR', '43' => 'PR', '44' => 'PR', '45' => 'PR', '46' => 'PR',
        '47' => 'SC', '48' => 'SC', '49' => 'SC',
        '51' => 'RS', '53' => 'RS', '54' => 'RS', '55' => 'RS',
        '61' => 'DF', '62' => 'GO', '64' => 'GO', '63' => 'TO', '65' => 'MT', '66' => 'MT', '67' => 'MS',
        '68' => 'AC', '69' => 'RO',
        '71' => 'BA', '73' => 'BA', '74' => 'BA', '75' => 'BA', '77' => 'BA',
        '79' => 'SE',
        '81' => 'PE', '87' => 'PE',
        '82' => 'AL', '83' => 'PB', '84' => 'RN',
        '85' => 'CE', '88' => 'CE',
        '86' => 'PI', '89' => 'PI',
        '91' => 'PA', '93' => 'PA', '94' => 'PA',
        '92' => 'AM', '97' => 'AM',
        '95' => 'RR', '96' => 'AP',
        '98' => 'MA', '99' => 'MA',
    ];

    /**
     * Mapa id => UF dos fornecedores ativos. DDD do WhatsApp/telefone primeiro
     * (mesma derivacao usada na Lista DG), UF explicita no location como
     * fallback. Cache de 10min — a base muda raramente.
     */
    private function getUfMap(): array
    {
        return Cache::remember('directory_suppliers_uf_map', 600, function (): array {
            try {
                $rows = DB::select('SELECT id, location, whatsapp, phone FROM directory_suppliers WHERE is_active = 1');
            } catch (\Exception $e) {
                return [];
            }

            $map = [];
            foreach ($rows as $row) {
                $uf = $this->deriveUf($row->whatsapp ?? null, $row->phone ?? null, $row->location ?? null);
                if ($uf !== null) {
                    $map[(int) $row->id] = $uf;
                }
            }

            return $map;
        });
    }

    private function deriveUf(?string $whatsapp, ?string $phone, ?string $location): ?string
    {
        foreach ([$whatsapp, $phone] as $tel) {
            if (!$tel) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $tel);
            if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
                $digits = substr($digits, 2);
            }
            $ddd = substr($digits, 0, 2);
            if (isset(self::DDD_UF[$ddd])) {
                return self::DDD_UF[$ddd];
            }
        }

        if ($location && preg_match('/\b(' . implode('|', array_unique(array_values(self::DDD_UF))) . ')\b/', $location, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Contagem de fornecedores ativos por UF (desc) para os chips do frontend. */
    private function getUfsMeta(): array
    {
        $counts = [];
        foreach ($this->getUfMap() as $uf) {
            $counts[$uf] = ($counts[$uf] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Lista distinta de categorias com contagem de fornecedores ativos.
     * Usa JSON_TABLE (MySQL 8+) para expandir o array JSON de categorias.
     *
     * Retorna array de {category: count}.
     */
    private function getCategoriesMeta(): array
    {
        try {
            $rows = DB::select("
                SELECT jt.category, COUNT(*) as cnt
                FROM directory_suppliers ds
                JOIN JSON_TABLE(
                    ds.categories,
                    '\$[*]' COLUMNS (category VARCHAR(255) PATH '\$')
                ) AS jt ON TRUE
                WHERE ds.is_active = 1
                GROUP BY jt.category
                ORDER BY jt.category
            ");

            return collect($rows)
                ->mapWithKeys(fn ($row) => [$row->category => (int) $row->cnt])
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }
}
