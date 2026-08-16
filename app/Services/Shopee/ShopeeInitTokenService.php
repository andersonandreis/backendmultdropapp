<?php

namespace App\Services\Shopee;

use Illuminate\Support\Facades\DB;

/**
 * MUL-314 — emissao do JWT curto que autentica o init OAuth da Shopee no hub central.
 *
 * Existia so dentro do OAuthInitTokenController, para o front chamar. Virou service
 * porque o backend do WL tambem precisa emitir (OAuthController::redirect, que roda
 * sem sessao do front) -- e duas copias da mesma regra e exatamente o que ja deu
 * errado outras vezes neste sistema.
 *
 * ATENCAO ao claim "sub": ele nao significa a mesma coisa em todo service.
 * ShopeeOAuthController::relayToService resolve o dono da conta assim:
 *
 *   service = hubai   -> clients WHERE legacy_id_login = user_id   (id do goolhub)
 *   demais services   -> clients WHERE user_id = user_id           (users.id do WL)
 *
 * Mandar users.id quando o service e "hubai" e perigoso: se existir um legacy.login
 * com esse mesmo id -- ids baixos colidem -- o fallback NOV-190 nao e acionado e o
 * HUB-079 cria User+Client a partir do legado errado, casando a loja Shopee no
 * cliente errado. Por isso o sub e escolhido por service, aqui, num lugar so.
 */
class ShopeeInitTokenService
{
    /**
     * APP_TENANT usa slug interno (sem hifen); config/shopee_oauth_services do hub
     * usa o nome canonico.
     */
    private const SVC_MAP = [
        'sellerglobal' => 'seller-global',
        'hubai'        => 'hubai',
        'fornecefy'    => 'fornecefy',
        'multdrop'     => 'multdrop',
        'mestoredrop'  => 'mestoredrop',
        'jtdrop'       => 'jtdrop',
        'dropksr'      => 'dropksr',
    ];

    public static function service(): string
    {
        $tenant = (string) config('federation.tenant', 'hubai');
        return self::SVC_MAP[$tenant] ?? $tenant;
    }

    /**
     * Identificador que o relay daquele service espera receber de volta.
     * Ver o bloco de ATENCAO no topo da classe.
     */
    public static function subjectParaUser(int $userId): ?string
    {
        $svc = self::service();

        if ($svc !== 'hubai') {
            return (string) $userId;
        }

        // service=hubai: o relay procura por legacy_id_login. Sem legado, cai no
        // fallback NOV-190, que aceita users.id -- entao mandar users.id e o certo
        // justamente nesse caso.
        $legacy = DB::table('clients')->where('user_id', $userId)->value('legacy_id_login');

        return $legacy ? (string) $legacy : (string) $userId;
    }

    /**
     * @return string|null null quando SHOPEE_INIT_JWT_SECRET nao esta configurado.
     */
    public static function emitir(int $userId): ?string
    {
        $secret = (string) config('services.shopee_init_jwt.secret', '');
        if ($secret === '') {
            return null;
        }

        $sub = self::subjectParaUser($userId);
        if ($sub === null || $sub === '') {
            return null;
        }

        $svc = self::service();

        $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'sub' => $sub,
            'svc' => $svc,
            'iat' => time(),
            'exp' => time() + 300,
            'iss' => $svc,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $b64 = fn ($x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
        $signInput = $b64($header) . '.' . $b64($payload);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signInput, $secret, true)), '+/', '-_'), '=');

        return $signInput . '.' . $sig;
    }
}
