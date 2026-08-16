<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Filesystem\Filesystem;

/**
 * INF-029: route:cache-atomic -- evita race condition do route:cache padrao.
 * O route:cache do Laravel chama route:clear (apaga) ANTES de escrever o novo.
 * Isso cria janela de microsegundos onde requests HTTP em andamento recebem:
 *   ErrorException: require(bootstrap/cache/routes-v7.php): Failed to open stream
 *
 * Esta versao:
 *   1. Gera o conteudo do cache em memoria
 *   2. Escreve em arquivo temporario
 *   3. Usa rename() atomico do OS para substituir o arquivo final sem janela de "arquivo nao existe"
 */
class RouteCacheAtomicCommand extends Command
{
    protected $signature   = 'route:cache-atomic';
    protected $description = 'INF-029: Gera cache de rotas atomicamente (sem race condition no routes-v7.php)';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $finalPath = $this->laravel->getCachedRoutesPath();
        $tmpPath   = $finalPath . '.tmp.' . getmypid();

        try {
            // Carrega rotas sem type hint restritivo (Laravel 12 retorna CompiledRouteCollection)
            $app    = $this->getFreshApplication();
            $routes = $app['router']->getRoutes();
            $routes->refreshNameLookups();
            $routes->refreshActionLookups();

            if (count($routes) === 0) {
                $this->components->error('Nenhuma rota encontrada na aplicacao.');
                return self::FAILURE;
            }

            foreach ($routes as $route) {
                $route->prepareForSerialization();
            }

            $stubPath = $this->laravel->basePath('vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/routes.stub');
            $stub     = $this->files->get($stubPath);
            $content  = str_replace('{{routes}}', var_export($routes->compile(), true), $stub);

            // Escreve em arquivo temporario primeiro
            $this->files->put($tmpPath, $content);

            // rename() e atomico no Linux/ext4 -- substitui finalPath sem janela de nao-existencia
            if (! rename($tmpPath, $finalPath)) {
                $this->files->delete($tmpPath);
                $this->components->error('Falha ao renomear arquivo de cache de rotas.');
                return self::FAILURE;
            }

            $this->components->info('Routes cached successfully (atomic).');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $this->components->error('Erro ao gerar cache de rotas: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function getFreshApplication()
    {
        // INF-039: aponta o cache de rotas da app fresca pra um path inexistente.
        // Sem isso, com cache presente o boot carregava o CACHE VELHO e o comando
        // re-serializava as rotas antigas — mudancas em routes/ nunca entravam
        // em producao via cron hubai-sync (que nao roda route:clear antes).
        $bogus = $this->laravel->bootstrapPath('cache/routes-v7.fresh-rebuild');
        $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $bogus;

        try {
            return tap(require $this->laravel->bootstrapPath('app.php'), function ($app) {
                $app->make(ConsoleKernelContract::class)->bootstrap();
            });
        } finally {
            unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
        }
    }
}
