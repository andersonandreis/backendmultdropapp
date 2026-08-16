<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-361 Fase A -- AntiStrikeGuard
 *
 * Verifica condicoes de segurança antes de cada geracao de video.
 * Loga resultados em strike_guard_events para auditoria.
 *
 * Checks:
 *   1. Avatar exclusivo (pipelines com rosto)
 *   2. Celebridade (pipelines trocar_rosto)
 *   3. Politica de conteudo basica
 */
class AntiStrikeGuard
{
    // Pipelines que requerem avatar exclusivo
    private const EXCLUSIVE_AVATAR_PIPELINES = [
        "avatar_apresentando",
        "trocar_rosto",
        "showcase_silencioso",
    ];

    public function __construct(
        private ClientAvatarResolver $avatarResolver,
    ) {}

    /**
     * Executa todos os checks pre-geracao.
     *
     * @return array{allowed:bool, warnings:array, blocks:array}
     */
    public function checkAll(int $userId, int $clientId, string $pipeline, array $context = []): array
    {
        $warnings = [];
        $blocks   = [];

        // Check 1: avatar exclusivo
        if (in_array($pipeline, self::EXCLUSIVE_AVATAR_PIPELINES)) {
            $avatarResult = $this->avatarResolver->resolve($clientId, $pipeline);
            if (!$avatarResult["can_proceed"]) {
                $blocks[] = [
                    "check"  => "avatar_exclusive",
                    "reason" => $avatarResult["block_reason"],
                ];
                $this->log($userId, $clientId, "avatar_exclusive", "blocked", $pipeline, $avatarResult["block_reason"], $context);
            } else {
                $this->log($userId, $clientId, "avatar_exclusive", "allowed", $pipeline, null, $context);
            }
        }

        // Check 2: celebridade (se trocar_rosto e tem face_url)
        if ($pipeline === "trocar_rosto" && !empty($context["tos_confirmed"])) {
            // ToS confirmado pelo cliente -- registrar mas permitir
            $this->log($userId, $clientId, "celebrity", "allowed", $pipeline,
                "TOS confirmado pelo cliente", $context);
        } elseif ($pipeline === "trocar_rosto") {
            $warnings[] = [
                "check"   => "celebrity",
                "message" => "Confirme que tem permissao de uso do rosto neste video (ToS TikTok).",
            ];
        }

        // Check 3: politica de conteudo basica (prompt review)
        if (!empty($context["prompt"])) {
            $contentBlock = $this->checkContentPolicy($context["prompt"]);
            if ($contentBlock) {
                $blocks[] = ["check" => "content_policy", "reason" => $contentBlock];
                $this->log($userId, $clientId, "content_policy", "blocked", $pipeline, $contentBlock, $context);
            }
        }

        $allowed = empty($blocks);
        if ($allowed && !empty($warnings)) {
            $this->log($userId, $clientId, "overall", "warned", $pipeline, null, $context);
        } elseif ($allowed) {
            $this->log($userId, $clientId, "overall", "allowed", $pipeline, null, $context);
        }

        return [
            "allowed"  => $allowed,
            "warnings" => $warnings,
            "blocks"   => $blocks,
        ];
    }

    private function checkContentPolicy(string $prompt): ?string
    {
        $blocked = ["crianca", "menor", "infant", "child", "nude", "nudez", "pornografia"];
        $lower   = mb_strtolower($prompt);
        foreach ($blocked as $term) {
            if (str_contains($lower, $term)) {
                return "Conteudo nao permitido detectado: {}.";
            }
        }
        return null;
    }

    private function log(int $userId, int $clientId, string $checkType, string $result,
        string $pipeline, ?string $reason, array $context): void
    {
        try {
            DB::table("strike_guard_events")->insert([
                "user_id"    => $userId,
                "client_id"  => $clientId,
                "check_type" => $checkType,
                "result"     => $result,
                "pipeline"   => $pipeline,
                "reason"     => $reason,
                "context"    => json_encode($context),
                "created_at" => now(),
                "updated_at" => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning("[SEL-361 AntiStrikeGuard] log failed", ["err" => $e->getMessage()]);
        }
    }
}
