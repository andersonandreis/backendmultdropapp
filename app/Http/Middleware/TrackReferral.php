<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEL-345: Captura ?ref=CODIGO na URL e planta cookie 60 dias.
 * Registrado no grupo 'web' e 'api' via bootstrap/app.php ou Kernel.
 */
class TrackReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        $ref = $request->query('ref');
        if ($ref && preg_match('/^[A-Z0-9]{6,32}$/i', $ref)) {
            cookie()->queue(
                cookie('affiliate_ref', strtoupper($ref), 60 * 24 * 60) // 60 dias em minutos
            );
        }

        return $next($request);
    }
}
