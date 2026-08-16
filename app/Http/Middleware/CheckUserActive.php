<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // MUL-312: regra de acesso unica em User::motivoSemAcesso(). Perder acesso
        // derruba os tokens vivos -- senao o cliente inativado hoje segue usando o
        // token de ontem ate ele expirar.
        if ($motivo = $user->motivoSemAcesso()) {
            $user->tokens()->delete();
            return response()->json(['message' => $motivo], 403);
        }

        return $next($request);
    }
}
