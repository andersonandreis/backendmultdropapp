<?php

namespace App\Providers;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use App\Services\Integrations\Contracts\PaymentGatewayInterface;
use App\Services\Integrations\Payments\PagarmeService;
use Filament\Facades\Filament;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registra PagarmeService como driver padrao de pagamento
        $this->app->bind(PaymentGatewayInterface::class, PagarmeService::class);

        // SEL-381: KLING_MODE=browser injeta KlingBrowserService (Consumer Pro via Playwright)
        // SEL-486: VIDEO_ENGINE=veo troca o MOTOR pro Google Flow / Veo, mantendo o
        // mesmo "carro" (VeoBrowserService estende KlingBrowserService — mesma
        // interface, mesma fila, mesmo job; so muda o worker Node no fim).
        $this->app->bind(\App\Services\Ai\KlingService::class, function ($app) {
            // SEL-411: era env() direto aqui. Com config cache ligado (5 dos 7 backends
            // ja tem), env() devolve null e o binding caia silenciosamente no
            // KlingService (API sem saldo). Agora le de config/services.php.

            // SEL-486: motor Veo. So assume o lugar quando OS DOIS concordam —
            // VIDEO_ENGINE=veo E veo.browser_enabled=true — pra ninguem ligar o Veo
            // pela metade. Nos outros 6 backends nenhum tem essas envs -> default
            // 'kling' -> este ramo nunca dispara neles.
            if (config('services.video_engine') === 'veo' && config('services.veo.browser_enabled')) {
                return new \App\Services\Ai\VeoBrowserService();
            }

            if (config('services.kling.mode') === 'browser' && config('services.kling.browser_enabled')) {
                return new \App\Services\Ai\KlingBrowserService();
            }
            return new \App\Services\Ai\KlingService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SEL-LOGINPORCONTA (13/08) — o limite de login era por IP, e isso
        // derruba gente inocente.
        //
        // MEDIDO hoje: o proprio Ruan levou 429 as 21:26. Causa: o limite era
        // `throttle:20,1` POR IP, e varias pessoas dividem o mesmo IP —
        // inclusive as sondas de teste que rodam na maquina dele. Uma rajada
        // de um derruba o login de TODOS que saem por aquele IP. Cliente
        // pagante que bate nessa tela desiste e nao reclama.
        //
        // Agora sao duas cercas, e a que importa e a primeira:
        //   - por CONTA (e-mail + ip): 8/min. Segura ataque de senha numa conta
        //     especifica sem punir quem esta do lado.
        //   - por IP: 60/min. Rede inteira (escritorio, 4G) cabe, mas script
        //     que varre milhares de contas ainda bate no teto.
        RateLimiter::for('login', function (Request $request) {
            $conta = Str::lower((string) $request->input('email')) . '|' . $request->ip();

            return [
                Limit::perMinute(8)->by('login-conta:' . $conta),
                Limit::perMinute(60)->by('login-ip:' . $request->ip()),
            ];
        });

        // SEL-353 Ruan 24/07 21:10: envia email auto ao criar afiliado
        \App\Models\Affiliate::observe(\App\Observers\AffiliateObserver::class);

        Filament::serving(function () {
            app()->setLocale('pt_BR');
        });

        // NOV-214: WlClientObserver — auditoria de deactivate/delete/reactivate em clients (antifraude WL)
        if (env("WL_ANTIFRAUDE_ENABLED", false)) {
            AppModelsClient::observe(AppObserversWlClientObserver::class);
        }

        // UserObserver registrado em ObserverServiceProvider — removido daqui para evitar duplo registro
        // que causava criacao de Client duplicado (FOR-022)
    }
}
