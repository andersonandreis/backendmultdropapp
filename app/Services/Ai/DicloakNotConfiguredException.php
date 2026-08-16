<?php

namespace App\Services\Ai;

/**
 * SEL-425 — Lançada quando DICLOAK_TUNNEL_URL não está configurado.
 * O VideoEnginePool captura esta exception e pula para o próximo motor
 * sem contar como falha operacional do engine.
 */
class DicloakNotConfiguredException extends \RuntimeException {}
