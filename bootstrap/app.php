<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Sentry\Laravel\Integration;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__."/../routes/web.php",
        api: __DIR__."/../routes/api.php",
        commands: __DIR__."/../routes/console.php",
        health: "/up",
        then: function() {
            require base_path("routes/dropautopecas.php");
            require base_path("routes/federation.php"); // NOV-171-B
            require base_path("routes/external-video.php"); // TOK-22 — intake Tokfy (prioridade baixa)
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prependToGroup("api", \App\Http\Middleware\LogLoginAttempt::class); // SEL-LOGINLOG: o mais externo, pra enxergar ate o 429 do throttle
        $middleware->prependToGroup("api", \App\Http\Middleware\CookieTokenToBearer::class);
        $middleware->prependToGroup("api", \App\Http\Middleware\SanctumFromQuery::class); // SEL-360 Fase 2: SSE token via ?token=xxx
        $middleware->appendToGroup("api", \App\Http\Middleware\LogApiErrors::class);
        $middleware->appendToGroup("api", \App\Http\Middleware\RestrictFreeAccess::class); // SEL-082 F1 — bloqueia tiktok_free fora da allowlist
        $middleware->appendToGroup("api", \App\Http\Middleware\RestrictLiveOnlyAccess::class); // SEL-408 — cerca conta só-live (extensão + /lives, nada mais). No-op fora do seller.global (plano live_only só existe lá).
        $middleware->appendToGroup("api", \App\Http\Middleware\EnforceWlBillingGate::class); // SEL-424 gate cobranca WL 402
        // NOV-046-G: LogMigracaoEvents removido dos grupos globais (web + api).
        // Era aplicado em TODA request, causando N queries nao-indexadas em rotas
        // de alto volume (Shopee/ML webhooks). Agora exposto apenas como alias
        // "migracao.log" para aplicar SÓ no route group /api/migracao/* (routes/api.php).
        $middleware->alias([
            // SEL-LOGINLOG (13/08): registro de tentativa de login. Ver a classe.
            'log.login' => \App\Http\Middleware\LogLoginAttempt::class,
            "check.user.active" => \App\Http\Middleware\CheckUserActive::class,
            "role" => \App\Http\Middleware\CheckRole::class,
            "tenant.api" => \App\Http\Middleware\TenantApiAuth::class,
            "tenant.idempotency" => \App\Http\Middleware\TenantApiIdempotency::class,
            "tenant.write" => \App\Http\Middleware\TenantApiWriteCheck::class,
            "tenant.context" => \App\Http\Middleware\EnsureTenantContext::class,
            "verify.hubai.signature" => \App\Http\Middleware\VerifyHubAISignature::class,
            "drop.module" => \App\Http\Middleware\RequiresDropModule::class,
            "swagger.auth" => \App\Http\Middleware\SwaggerAuth::class,
            "gabriel.auth" => \App\Http\Middleware\GabrielAuthMiddleware::class,
            "gabriel.api_key" => \App\Http\Middleware\GabrielApiKeyMiddleware::class,
            "migracao.log" => \App\Http\Middleware\LogMigracaoEvents::class, // MUL-029 / NOV-046-G
            "client.required" => \App\Http\Middleware\EnsureClientExists::class, // MUL-FIX-2
            "internal.key" => \App\Http\Middleware\InternalKeyMiddleware::class, // NOV-061
            "dropautopecas.api" => \App\Http\Middleware\DropAutoApiAuth::class,
            "auth.federation" => \App\Http\Middleware\AuthFederationToken::class, // NOV-171-B
            "verify.federation.hmac" => \App\Http\Middleware\VerifyFederationHmac::class, // NOV-171-C
            "supplier.panel" => \App\Http\Middleware\SupplierPanelAccess::class, // MUL-151
            "restrict.free" => \App\Http\Middleware\RestrictFreeAccess::class, // SEL-082 F1
            "restrict.live_only" => \App\Http\Middleware\RestrictLiveOnlyAccess::class, // SEL-408
            "auth.kalodata_token" => \App\Http\Middleware\AuthKalodataToken::class, // SEL-462 Fase 1
            "track.referral" => \App\Http\Middleware\TrackReferral::class, // SEL-345
            "lgpd.mask" => \App\Http\Middleware\LgpdMaskingMiddleware::class, // SEL-357 espelho LGPD
            "sanctum.query" => \App\Http\Middleware\SanctumFromQuery::class, // SEL-360 Fase 2 SSE token via query
            "wl.billing.gate" => \App\Http\Middleware\EnforceWlBillingGate::class, // SEL-424 gate cobranca WL 402
            "external.video.token" => \App\Http\Middleware\ExternalVideoTokenAuth::class, // TOK-22
            "convite.active" => \App\Http\Middleware\EnsureInviteTrialActive::class, // SEL-CONVITE: corta trial pos-24h
        ]);
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn() => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), "api/")) {
                return response()->json(["message" => "Unauthenticated."], 401);
            }
            // Rotas Filament admin -> redirecionar para /admin/login
            if (str_starts_with($request->path(), "admin")) {
                return redirect("/admin/login");
            }
            // Painel Filament /app -> redirecionar para /app/login
            if (str_starts_with($request->path(), "app")) {
                return redirect("/app/login");
            }
            // Rotas web com middleware auth (ex: /oauth/mercadolivre, /bling/auth, /orders/print-label)
            // Sem rota "login" definida — redirecionar pro painel admin evita o RouteNotFoundException/loop
            return redirect(config("app.frontend_url", "https://fornecefy.io"));
        });
        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), "api/")) {
                return response()->json(["message" => "Forbidden."], 403);
            }
        });
        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), "api/")) {
                return response()->json(["message" => "Not found."], 404);
            }
        });
        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), "api/")) {
                $msg = $e->getMessage() ?: (\Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()] ?? "Error");
                return response()->json(["message" => $msg], $e->getStatusCode());
            }
        });
    })->create();
