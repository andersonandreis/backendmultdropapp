<?php

namespace App\Services\Integrations\Contracts;

use Illuminate\Http\Request;

/**
 * Contrato para handlers de webhook de cada plataforma (marketplace, ERP, pagamento, etc.).
 *
 * Cada plataforma implementa esta interface com suas regras especificas
 * de validacao de assinatura, extracao de topico e despacho de jobs.
 */
interface WebhookHandlerInterface
{
    /**
     * Valida a assinatura da requisicao conforme o protocolo da plataforma.
     * Retorna false para rejeitar com 401.
     */
    public function validateSignature(Request $request): bool;

    /**
     * Extrai o topico/tipo do evento (ex: "orders", "items", "shipments").
     */
    public function extractTopic(Request $request): string;

    /**
     * Extrai o resource/ID do recurso afetado (ex: "/orders/123456").
     */
    public function extractResource(Request $request): string;

    /**
     * Extrai o user_id do vendedor na plataforma, se disponivel.
     */
    public function extractUserId(Request $request): ?string;

    /**
     * Despacha o job apropriado baseado no topico e resource.
     */
    public function dispatchJob(string $topic, string $resource, ?string $userId): void;
}
