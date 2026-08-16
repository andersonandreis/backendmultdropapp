<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege o Swagger UI / documentacao da API em producao.
 * 
 * Em producao (APP_ENV=production), exige header ou query param:
 *   X-Swagger-Token: <valor de SWAGGER_SECRET_TOKEN no .env>
 * 
 * Em outros environments (local, staging) libera acesso sem restricao.
 */
class SwaggerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            $expectedToken = config('services.swagger.secret_token');

            // Se nao ha token configurado, bloqueia completamente em producao
            if (empty($expectedToken)) {
                abort(403, 'API documentation is disabled in production.');
            }

            $providedToken = $request->header('X-Swagger-Token')
                ?? $request->query('swagger_token');

            if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
                abort(403, 'Access to API documentation requires a valid token.');
            }
        }

        return $next($request);
    }
}
