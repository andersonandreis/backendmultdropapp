<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TOK-22 -- autenticacao servidor-a-servidor do intake de video externo.
 *
 * De proposito NAO usa Sanctum: nao existe usuario nem sessao aqui, e o Tokfy
 * (outro backend) so precisa provar que e ele. Um segredo compartilhado no
 * header resolve, e evita ter que criar/rotacionar token de usuario fantasma.
 *
 * Le de config() e nao de env(): com config cache ligado (o caso deste backend)
 * env() devolve null e o middleware liberaria/derrubaria tudo calado -- foi
 * exatamente esse o bug do SEL-411 no binding do motor.
 */
class ExternalVideoTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $esperado = (string) config('services.external_video.shared_token', '');
        $recebido = (string) $request->header('X-External-Video-Token', '');

        // Sem segredo configurado o endpoint fica FECHADO, nunca aberto.
        // Um `if ($esperado === $recebido)` ingenuo deixaria tudo passar quando
        // as duas pontas fossem string vazia.
        if ($esperado === '') {
            Log::error('[TOK-22] EXTERNAL_VIDEO_SHARED_TOKEN ausente — intake externo recusando tudo');

            return response()->json(['message' => 'external_video_intake_disabled'], 503);
        }

        if ($recebido === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['message' => 'invalid_token'], 401);
        }

        return $next($request);
    }
}
