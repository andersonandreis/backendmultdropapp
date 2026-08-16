<?php

namespace App\Jobs;

use App\Models\AiCreator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * SEL-217 — SyncTokfyCreatorsJob
 *
 * Sincroniza perfis da tabela social_profiles do Supabase Tokfy
 * para ai_creators local. Roda diariamente às 05:00 BRT.
 *
 * Fluxo:
 * 1. Busca todos os registros de social_profiles via Supabase REST API
 * 2. Upsert em ai_creators (dedup por tokfy_sync_id ou handle)
 * 3. Recalcula rank_position sequencial (1..N) por estimated_revenue DESC
 *    — apenas creators com estimated_revenue > 0 recebem rank
 * 4. Creators "auxiliares" (estimated_revenue = 0) ficam no fim sem rank
 *
 * Credenciais via .env:
 *   TOKFY_SUPABASE_URL=https://lwdnwaxpfhihheylywsz.supabase.co
 *   TOKFY_SUPABASE_ANON_KEY=<anon_key>
 */
class SyncTokfyCreatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    /** Endpoint Supabase Tokfy */
    private string $supabaseUrl;
    private string $supabaseKey;

    public function __construct()
    {
        $this->supabaseUrl = rtrim(config('services.tokfy.supabase_url', env('TOKFY_SUPABASE_URL', '')), '/');
        $this->supabaseKey = config('services.tokfy.supabase_key', env('TOKFY_SUPABASE_ANON_KEY', ''));
    }

    public function handle(): void
    {
        Log::info('[SEL-217] SyncTokfyCreatorsJob started');

        $synced  = 0;
        $skipped = 0;

        // 1. Buscar perfis do Supabase Tokfy via REST API
        $profiles = $this->fetchFromSupabase();

        if (empty($profiles)) {
            Log::warning('[SEL-217] Nenhum perfil retornado do Supabase Tokfy — abortando sync');
            $this->recomputeRanks();
            return;
        }

        Log::info('[SEL-217] Perfis recebidos do Supabase Tokfy', ['count' => count($profiles)]);

        // 2. Upsert cada perfil
        foreach ($profiles as $profile) {
            try {
                $this->upsertCreator($profile);
                $synced++;
            } catch (\Throwable $e) {
                Log::warning('[SEL-217] Erro ao upsert creator', [
                    'profile_id' => $profile['id'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        // 3. Recalcular ranks
        $this->recomputeRanks();

        Log::info('[SEL-217] SyncTokfyCreatorsJob done', [
            'synced'  => $synced,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Busca todos os social_profiles do Supabase Tokfy via REST.
     * Usa paginação de 1000 registros (limite Supabase).
     */
    private function fetchFromSupabase(): array
    {
        if (empty($this->supabaseUrl) || empty($this->supabaseKey)) {
            Log::warning('[SEL-217] TOKFY_SUPABASE_URL ou TOKFY_SUPABASE_ANON_KEY não configurados');
            return [];
        }

        $all    = [];
        $offset = 0;
        $limit  = 1000;

        do {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'apikey'        => $this->supabaseKey,
                        'Authorization' => 'Bearer ' . $this->supabaseKey,
                        'Accept'        => 'application/json',
                        'Range'         => "{$offset}-" . ($offset + $limit - 1),
                    ])
                    ->get("{$this->supabaseUrl}/rest/v1/social_profiles", [
                        'select'  => 'id,handle,name,avatar_url,bio,followers,videos_count,estimated_revenue,gmv,commission,commission_items,following,likes_count,country,created_at,updated_at',
                        'order'   => 'estimated_revenue.desc',
                        'limit'   => $limit,
                        'offset'  => $offset,
                    ]);

                if (!$response->successful()) {
                    Log::warning('[SEL-217] Supabase REST error', [
                        'status' => $response->status(),
                        'body'   => substr($response->body(), 0, 500),
                    ]);
                    break;
                }

                $rows = $response->json() ?? [];

                if (empty($rows)) {
                    break;
                }

                $all    = array_merge($all, $rows);
                $offset += $limit;

                // Se retornou menos que o limite, não tem mais páginas
                if (count($rows) < $limit) {
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('[SEL-217] Exceção ao buscar Supabase', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        return $all;
    }

    /**
     * Upsert de um perfil Tokfy em ai_creators.
     * Dedup por tokfy_sync_id (ID Supabase) ou handle (se não tiver sync_id).
     */
    private function upsertCreator(array $profile): void
    {
        $tokfyId = (string) ($profile['id'] ?? '');
        $handle  = $this->normalizeHandle($profile['handle'] ?? '');

        if (empty($handle)) {
            return;
        }

        // Tenta encontrar por tokfy_sync_id primeiro (mais preciso), depois por handle
        $creator = AiCreator::where('tokfy_sync_id', $tokfyId)->first()
            ?? AiCreator::where('handle', $handle)->first();

        $data = [
            'handle'            => $handle,
            'name'              => $profile['name'] ?? $handle,
            'avatar_url'        => $profile['avatar_url'] ?? null,
            'bio'               => $profile['bio'] ?? null,
            'followers'         => (int) ($profile['followers'] ?? 0),
            'videos_count'      => (int) ($profile['videos_count'] ?? 0),
            'estimated_revenue' => $this->parseDecimal($profile['estimated_revenue'] ?? 0),
            'gmv'               => $this->parseDecimal($profile['gmv'] ?? null),
            'commission'        => $this->parseDecimal($profile['commission'] ?? null),
            'commission_items'  => isset($profile['commission_items']) ? (int) $profile['commission_items'] : null,
            'following'         => isset($profile['following']) ? (int) $profile['following'] : null,
            'likes_count'       => isset($profile['likes_count']) ? (int) $profile['likes_count'] : null,
            'country'           => $profile['country'] ?? 'BR',
            'source'            => 'tokfy',
            'tokfy_sync_id'     => $tokfyId ?: null,
            'tokfy_synced_at'   => now(),
            'is_visible'        => true,
            'is_approved'       => true,
        ];

        if ($creator) {
            $creator->update($data);
        } else {
            AiCreator::create($data);
        }
    }

    /**
     * Recalcula rank_position sequencial para todos os creators visíveis+aprovados.
     * Critério: estimated_revenue DESC, depois followers DESC.
     * Creators com revenue=0 ficam no fim (rank NULL).
     */
    private function recomputeRanks(): void
    {
        // Zero ranks de todos primeiro
        AiCreator::where('is_visible', true)
            ->where('is_approved', true)
            ->update(['rank_position' => null]);

        // Atribui ranks 1..N para os com revenue > 0
        $creators = AiCreator::where('is_visible', true)
            ->where('is_approved', true)
            ->where('estimated_revenue', '>', 0)
            ->orderByDesc('estimated_revenue')
            ->orderByDesc('followers')
            ->get(['id']);

        foreach ($creators as $index => $creator) {
            DB::table('ai_creators')
                ->where('id', $creator->id)
                ->update(['rank_position' => $index + 1]);
        }

        Log::info('[SEL-217] Ranks recalculados', ['ranked' => $creators->count()]);
    }

    private function normalizeHandle(string $handle): string
    {
        // Remove @ inicial se presente
        return ltrim(trim($handle), '@');
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}
