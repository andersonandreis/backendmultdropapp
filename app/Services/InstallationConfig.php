<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * NOV-181: configuracao da instalacao lida do BANCO (tabela installation_settings).
 *
 * O repo e compartilhado entre hub (api.hubai.io) e WLs (multdrop/fornecefy/
 * mestoredrop) — o comportamento de cada backend e definido por dados, nao por env:
 *
 *   installation.role                     => hub | whitelabel
 *   installation.hub_url                  => URL base do hub central
 *   marketplace.connection_mode.shopee    => own | bridge
 *   marketplace.connection_mode.mercadolivre / .bling => own | bridge
 *   bridge.wl_urls                        => JSON array de URLs das WLs espelho (so no hub)
 *
 * Fallback seguro: sem tabela ou sem registro => whitelabel + own (comportamento atual).
 * Cache de 60s => alterar no banco reflete sem deploy/restart.
 */
class InstallationConfig
{
    public const CACHE_KEY = 'installation_settings:all';
    public const CACHE_TTL = 60;

    /** @return array<string, string|null> */
    public function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
                return DB::table('installation_settings')->pluck('value', 'key')->all();
            });
        } catch (\Throwable $e) {
            // Tabela pode nao existir ainda (instalacao sem migration) — fallback seguro
            return [];
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->all()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function set(string $key, ?string $value): void
    {
        DB::table('installation_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );

        Cache::forget(self::CACHE_KEY);
    }

    public function role(): string
    {
        return $this->get('installation.role', 'whitelabel');
    }

    public function isHub(): bool
    {
        return $this->role() === 'hub';
    }

    /**
     * Modo de conexao POR plataforma: own (renova localmente, comportamento atual)
     * ou bridge (hub central renova; esta instalacao so espelha).
     */
    public function connectionMode(string $platform): string
    {
        return $this->get("marketplace.connection_mode.{$platform}", 'own');
    }

    /**
     * true quando esta instalacao e uma WL em modo bridge pra plataforma.
     */
    public function usesBridge(string $platform): bool
    {
        return ! $this->isHub() && $this->connectionMode($platform) === 'bridge';
    }

    /**
     * MUL-212 F2: pull periodico de PEDIDOS de marketplace nesta instalacao.
     * Chave: orders.periodic_pull.{platform} => on | off (default on = comportamento atual).
     * Controle dinamico por instalacao via banco (cache 60s, sem deploy).
     */
    public function pullsOrders(string $platform): bool
    {
        $platform = $platform === 'mercado_livre' ? 'mercadolivre' : $platform;

        return $this->get("orders.periodic_pull.{$platform}", 'on') !== 'off';
    }

    /**
     * MUL-212 F2: WL nunca puxa pedidos de conta gerida centralmente — o hub
     * puxa (pipeline de custo/imagem) e entrega via fanout. Puxar em paralelo
     * gerava pedido cru + duplicatas (MUL-187).
     */
    public function skipsCentralAccountPull(bool $centrallyManaged): bool
    {
        return ! $this->isHub() && $centrallyManaged;
    }

    public function hubUrl(): string
    {
        return rtrim($this->get('installation.hub_url', 'https://api.hubai.io'), '/');
    }

    /**
     * URLs base das WLs que espelham tokens deste hub (usado pela propagacao).
     *
     * @return string[]
     */
    public function mirrorUrls(): array
    {
        $raw = $this->get('bridge.wl_urls', '[]');
        $urls = json_decode($raw, true);

        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($u) => rtrim(trim((string) $u), '/'),
            $urls
        )));
    }
}
