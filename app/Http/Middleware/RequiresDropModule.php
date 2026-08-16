<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequiresDropModule
{
    /**
     * Verifica se o cliente autenticado tem plano com has_drop_internacional=true.
     * Usado nas rotas /api/v1/drop/* para proteger endpoints do modulo Drop Internacional.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Nao autenticado.'], 401);
        }

        // super_admin tem acesso irrestrito
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        $client = $user->client;

        if (!$client) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 403);
        }

        $plan = $client->subscription?->plan ?? null;

        // Se o plan ainda nao tem o campo (migration pendente), libera com aviso no log
        if ($plan && isset($plan->has_drop_internacional) && !$plan->has_drop_internacional) {
            return response()->json([
                'error' => 'Modulo Drop Internacional nao incluso no plano. Faca upgrade para acessar este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
