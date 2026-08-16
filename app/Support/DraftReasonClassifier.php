<?php

namespace App\Support;

/**
 * INF-036 — Classificacao de motivo de rascunho.
 *
 * Cada draft_reason cai em UMA de 3 classes:
 *
 *   TRANSIENT     — falha temporaria (token/API instavel). Retentar com backoff
 *                   curto; escalar apos janela X.
 *   PENDING_DATA  — pedido esta esperando dado que vira de outro fluxo/webhook.
 *                   Retentar sem teto (backoff progressivo); nao alertar.
 *   PERMANENT     — falha estrutural. Nao adianta retentar. Alertar humano.
 *
 * Motivos desconhecidos caem em PENDING_DATA (fail-safe: continua tentando).
 */
class DraftReasonClassifier
{
    public const TRANSIENT    = 'transient';
    public const PENDING_DATA = 'pending_data';
    public const PERMANENT    = 'permanent';

    /**
     * @var array<string, string>  reason(prefix-match) => class
     * Ordem importa: prefixos mais especificos primeiro (str_starts_with).
     */
    private const MAP = [
        // === PERMANENT === falha estrutural — parar de tentar
        'order_not_found_in_api'             => self::PERMANENT,
        'token_unavailable'                  => self::PERMANENT,
        'deleted_account'                    => self::PERMANENT,
        'no_shopee_account'                  => self::PERMANENT,
        'enrichment_exhausted'               => self::PERMANENT,
        'mul202_backfill_publicado_sem_custo'=> self::PERMANENT,
        'mul202c_backfill_no_cost_source'    => self::PERMANENT,

        // === TRANSIENT === falha temporaria — retentar rapido
        'shopee_api_error'                   => self::TRANSIENT,
        'invalid_acceess_token'              => self::TRANSIENT,
        'invalid_access_token'               => self::TRANSIENT,
        'error_auth'                         => self::TRANSIENT,
        'api_error'                          => self::TRANSIENT,
        'empty_response'                     => self::TRANSIENT,
        'refresh_pending'                    => self::TRANSIENT,

        // === PENDING_DATA === esperando outro fluxo — retentar sem pressa
        'observer_safety_net'                => self::PENDING_DATA,
        'awaiting_hub_relay'                 => self::PENDING_DATA,
        'incomplete: paid_at'                => self::PENDING_DATA,
        'incomplete: items'                  => self::PENDING_DATA,
        'incomplete: customer_name'          => self::PENDING_DATA,
        'incomplete: total'                  => self::PENDING_DATA,
        'incomplete: supplier_cost'          => self::PENDING_DATA,
        'enricher_non_shopee'                => self::PENDING_DATA,
        'scheduler_recheck'                  => self::PENDING_DATA,
    ];

    /**
     * Retorna a classe para o motivo dado. Reason vazio/desconhecido => PENDING_DATA.
     */
    public static function classify(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return self::PENDING_DATA;
        }
        foreach (self::MAP as $prefix => $class) {
            if (str_starts_with($reason, $prefix)) {
                return $class;
            }
        }
        return self::PENDING_DATA;
    }

    public static function isPermanent(?string $reason): bool
    {
        return self::classify($reason) === self::PERMANENT;
    }

    public static function isTransient(?string $reason): bool
    {
        return self::classify($reason) === self::TRANSIENT;
    }

    public static function isPendingData(?string $reason): bool
    {
        return self::classify($reason) === self::PENDING_DATA;
    }
}
