<?php

use App\Http\Controllers\Api\V1\ExternalVideoIntakeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TOK-22 -- Intake de video de produtos EXTERNOS (hoje: Tokfy)
|--------------------------------------------------------------------------
|
| Arquivo separado de propósito. `routes/api.php` estava com alteracoes nao
| commitadas de outra sessao no servidor -- editar ele obrigaria a commitar
| trabalho alheio junto. O bootstrap ja carrega route files extras pelo hook
| `then:` (mesmo padrao de dropautopecas.php e federation.php).
|
| Autenticacao: segredo compartilhado servidor-a-servidor no header
| `X-External-Video-Token` (middleware `external.video.token`). Nao usa Sanctum
| porque nao ha usuario nem sessao envolvidos.
|
*/

Route::prefix('api/v1/external-video')
    ->middleware(['api', 'external.video.token'])
    ->group(function () {
        Route::post('/enqueue', [ExternalVideoIntakeController::class, 'enqueue'])
            ->name('external-video.enqueue');

        Route::get('/status/{motorJobId}', [ExternalVideoIntakeController::class, 'status'])
            ->name('external-video.status');
    });
