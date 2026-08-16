<?php

namespace App\Services\Ai;

/**
 * SEL-361 Fase A -- AntiCutOffGuard
 *
 * Guard que verifica ANTES do Kling render se o TTS estimado cabe na duracao do video.
 * Se nao couber, retorna false e uma mensagem de studio para o cliente aprovar encurtamento.
 */
class AntiCutOffGuard
{
    public function __construct(
        private ScriptTimingCalibratorService $calibrator,
    ) {}

    /**
     * Verifica se script cabe no video.
     *
     * @return array{ok:bool, message:string|null, suggestion:string|null}
     */
    public function check(string $script, int $durationSec, string $voice = "nova"): array
    {
        if (empty(trim($script))) {
            return ["ok" => true, "message" => null, "suggestion" => null];
        }

        $result = $this->calibrator->calibrate($script, $durationSec, $voice);

        if ($result["safe_to_generate"]) {
            return ["ok" => true, "message" => null, "suggestion" => null];
        }

        $overSec = round($result["estimated_seconds"] - $durationSec, 1);
        $message = "Roteiro muito longo! Estimei {$result["estimated_seconds"]}s de fala para um video de {$durationSec}s. "
            . "Vai cortar {$overSec}s da fala.";

        return [
            "ok"         => false,
            "message"    => $message,
            "suggestion" => "Encurto automaticamente pra voce? O hook e o CTA ficam. (Sim / Nao, eu edito)",
        ];
    }

    /**
     * Verifica e CORRIGE automaticamente (usa calibrator para encurtar).
     * Retorna calibration result com script_final pronto.
     */
    public function checkAndFix(string $script, int $durationSec, string $voice = "nova"): array
    {
        return $this->calibrator->calibrate($script, $durationSec, $voice);
    }
}
