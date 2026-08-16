<?php

namespace App\Console\Commands;

use App\Services\InstallationConfig;
use Illuminate\Console\Command;

/**
 * NOV-181: define no BANCO o papel desta instalacao e o modo de conexao
 * por plataforma de marketplace. Rodar uma vez por instalacao (idempotente).
 *
 * Exemplos:
 *   php artisan installation:setup hub --wl-urls=https://api.multdrop.app,https://api.fornecefy.io
 *   php artisan installation:setup whitelabel --shopee=bridge --hub-url=https://api.hubai.io
 *   php artisan installation:setup --show
 */
class InstallationSetupCommand extends Command
{
    protected $signature = 'installation:setup
                            {role? : hub | whitelabel}
                            {--shopee= : Modo de conexao Shopee (own|bridge)}
                            {--mercadolivre= : Modo de conexao Mercado Livre (own|bridge)}
                            {--bling= : Modo de conexao Bling (own|bridge)}
                            {--hub-url= : URL base do hub central (so WL)}
                            {--wl-urls= : URLs das WLs espelho, separadas por virgula (so hub)}
                            {--show : Apenas exibe a configuracao atual}';

    protected $description = 'Define role (hub|whitelabel) e modos de conexao de marketplace desta instalacao (config por banco, NOV-181)';

    public function handle(InstallationConfig $config): int
    {
        if ($this->option('show')) {
            return $this->show($config);
        }

        $role = $this->argument('role');
        if (! in_array($role, ['hub', 'whitelabel'], true)) {
            $this->error("role obrigatorio: hub | whitelabel (recebido: '{$role}'). Use --show pra ver o atual.");

            return self::FAILURE;
        }

        $config->set('installation.role', $role);

        foreach (['shopee', 'mercadolivre', 'bling'] as $platform) {
            $mode = $this->option($platform);
            if ($mode === null || $mode === '') {
                continue;
            }
            if (! in_array($mode, ['own', 'bridge'], true)) {
                $this->error("--{$platform} deve ser own|bridge (recebido: '{$mode}')");

                return self::FAILURE;
            }
            if ($role === 'hub' && $mode === 'bridge') {
                $this->error('Hub nao pode usar bridge — o hub E o centro de autenticacao.');

                return self::FAILURE;
            }
            $config->set("marketplace.connection_mode.{$platform}", $mode);
        }

        if ($hubUrl = $this->option('hub-url')) {
            $config->set('installation.hub_url', rtrim($hubUrl, '/'));
        }

        if ($wlUrls = $this->option('wl-urls')) {
            $urls = array_values(array_filter(array_map(
                fn ($u) => rtrim(trim($u), '/'),
                explode(',', $wlUrls)
            )));
            $config->set('bridge.wl_urls', json_encode($urls));
        }

        $this->info('Configuracao salva.');

        return $this->show($config);
    }

    private function show(InstallationConfig $config): int
    {
        $rows = [
            ['role', $config->role()],
            ['hub_url', $config->hubUrl()],
            ['shopee', $config->connectionMode('shopee')],
            ['mercadolivre', $config->connectionMode('mercadolivre')],
            ['bling', $config->connectionMode('bling')],
            ['wl_urls (espelhos)', implode(', ', $config->mirrorUrls()) ?: '-'],
        ];

        $this->table(['Config', 'Valor'], $rows);

        return self::SUCCESS;
    }
}
