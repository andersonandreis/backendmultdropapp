<?php

namespace App\Http\Controllers\Api\V1;

/**
 * SEL-258 — backward-compat alias para TiktokInsightsController.
 *
 * Rotas /api/v1/insights/tiktok/* continuam apontando para este nome de classe
 * (routes/api.php usa KalodataController::class). Este arquivo apenas estende
 * o controller unificado. Não remover nem adicionar métodos aqui.
 */
class KalodataController extends TiktokInsightsController
{
    // herda tudo de TiktokInsightsController
}
