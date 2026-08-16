<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-361 Fase A -- ScriptTimingCalibratorService
 *
 * Calibra a sincronia entre roteiro TTS e duracao do video Kling.
 * Problema atual: roteiro gerado para 15s demora 22s no TTS = fala cortada.
 *
 * Solucao:
 *   1. Estima duracao TTS do roteiro (chars / velocidade de fala)
 *   2. Se longo demais: encurta via GPT-4o-mini (mantendo hook+beneficio+CTA)
 *   3. Ajusta voice_speed (0.9-1.15) para encaixe fino
 *   4. Retorna script_final + parametros seguros para o Kling
 */
class ScriptTimingCalibratorService
{
    // Chars por segundo de fala para cada voice_speed
    // Medido empiricamente com voix OpenAI TTS (nova, shimmer, alloy)
    private const CHARS_PER_SEC_BASE = 14.5;  // a speed 1.0
    private const MIN_SPEED = 0.9;
    private const MAX_SPEED = 1.15;

    // Tolerancia: roteiro pode ocupar ate 90% da duracao do video
    private const OCCUPANCY_TARGET = 0.90;

    public function __construct(
        private OpenAiService $openai,
    ) {}

    /**
     * Calibra roteiro para caber na duracao desejada.
     *
     * @param string $script         Roteiro a calibrar
     * @param int    $desiredSeconds Duracao alvo do video
     * @param string $voice          Voice OpenAI TTS (nova|shimmer|alloy|echo|fable|onyx)
     * @return array{
     *   script_final: string,
     *   tts_speed: float,
     *   estimated_seconds: float,
     *   safe_to_generate: bool,
     *   was_shortened: bool,
     *   iterations: int,
     * }
     */
    public function calibrate(string $script, int $desiredSeconds, string $voice = "nova"): array
    {
        $maxTarget  = $desiredSeconds * self::OCCUPANCY_TARGET;
        $iterations = 0;
        $wasShorter = false;

        // Iteracao 1: tentar ajustar speed sem mexer no roteiro
        $speed    = 1.0;
        $estSec   = $this->estimateDuration($script, $speed);

        if ($estSec <= $maxTarget) {
            // Roteiro ja cabe -- verificar se pode ser um pouco mais lento
            if ($estSec < $maxTarget * 0.7 && $estSec > 0) {
                // Muito curto: deixar mais lento para preencher melhor
                $speed = min(self::MAX_SPEED, ($maxTarget * 0.9) / ($estSec / $speed));
            }
            return $this->result($script, $speed, $estSec, true, false, 0);
        }

        // Iteracao 2: tentar aumentar speed (menos fala = mais rapido)
        $fastSpeed    = self::MAX_SPEED;
        $fastEstimated = $this->estimateDuration($script, $fastSpeed);

        if ($fastEstimated <= $maxTarget) {
            return $this->result($script, $fastSpeed, $fastEstimated, true, false, 1);
        }

        // Iteracao 3: encurtar roteiro via GPT-4o-mini
        $iterations  = 2;
        $shortened   = $this->shortenScript($script, $desiredSeconds);
        $shortEstSec = $this->estimateDuration($shortened, 1.0);

        // Ajustar speed no roteiro encurtado
        if ($shortEstSec > 0) {
            $idealSpeed = min(self::MAX_SPEED, max(self::MIN_SPEED, ($maxTarget) / $shortEstSec));
        } else {
            $idealSpeed = 1.0;
        }

        $finalEst    = $this->estimateDuration($shortened, $idealSpeed);
        $safeToGen   = $finalEst <= $desiredSeconds;

        return $this->result($shortened, $idealSpeed, $finalEst, $safeToGen, true, $iterations);
    }

    /**
     * Verifica se roteiro cabe na duracao (guard rapido).
     */
    public function safeToGenerate(string $script, int $durationSec, string $voice = "nova"): bool
    {
        $estimated = $this->estimateDuration($script, 1.0);
        return $estimated <= ($durationSec * self::OCCUPANCY_TARGET);
    }

    // ─── Internos ───────────────────────────────────────────────────

    private function estimateDuration(string $script, float $speed): float
    {
        if ($speed <= 0) return 0;
        $chars = mb_strlen(strip_tags($script));
        // Chars por segundo ajustado pela speed (speed 1.15 = 15% mais rapido)
        $charsPerSec = self::CHARS_PER_SEC_BASE * $speed;
        return round($chars / $charsPerSec, 2);
    }

    private function shortenScript(string $script, int $desiredSeconds): string
    {
        try {
            $res = $this->openai->chat([
                ["role" => "system", "content" =>
                    "Voce e um copywriter expert em TikTok Shop. "
                    . "Encurte o roteiro abaixo para caber em EXATAMENTE {$desiredSeconds} segundos de fala. "
                    . "OBRIGATORIO manter: (1) hook dos primeiros 2s, (2) beneficio principal, (3) CTA final. "
                    . "NAO use reticencias. Retorne APENAS o roteiro encurtado, sem explicacao."],
                ["role" => "user", "content" => "Roteiro original:\n{$script}"],
            ], model: "gpt-4o-mini", maxTokens: 500);

            return trim($res) ?: $script;
        } catch (Throwable $e) {
            Log::warning("[SEL-361 ScriptTimingCalibrator] shorten failed", ["err" => $e->getMessage()]);
            return $script;
        }
    }

    private function result(string $script, float $speed, float $estSec, bool $safe, bool $wasShortened, int $iterations): array
    {
        return [
            "script_final"       => $script,
            "tts_speed"          => round($speed, 2),
            "estimated_seconds"  => $estSec,
            "safe_to_generate"   => $safe,
            "was_shortened"      => $wasShortened,
            "iterations"         => $iterations,
        ];
    }
}
