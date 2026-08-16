<?php

namespace App\Support;

/**
 * SEL-EMAILTOKFY (12/08) — PONTO UNICO de decisao de MARCA no backend.
 *
 * POR QUE ESTE ARQUIVO EXISTE
 * ---------------------------
 * O backend api.seller.global atende DUAS marcas com a MESMA base de contas:
 *   - seller.global  (dropshipping / TikTok Shop / fornecedores)
 *   - tokfy.io       (SO geracao de video com IA)
 * O front ja resolve isso em `src/config/brand.ts` (BRAND_CONFIG). Este arquivo
 * e o espelho de BACKEND desse mesmo mapa — os valores (nome, dominio, cor
 * accent, gradiente, nome do atendente, e-mail de suporte) foram COPIADOS de
 * brand.ts, nao inventados. Se brand.ts mudar, muda aqui tambem.
 *
 * Ate 12/08 o e-mail de "plano ativado" era 100% seller.global e ia sair assim
 * pros 317 clientes que compraram TOKFY. Regra dura do dono: cliente Tokfy nao
 * pode ver NADA de seller.global — nem nome, nem cor, nem link.
 *
 * COMO A MARCA E DECIDIDA
 * -----------------------
 * O banco NAO tem coluna de marca. A separacao real hoje e o PLANO:
 *   99  video_start  "Video IA — Start"      -> TOKFY
 *   100 video_pro    "Video IA — Ilimitado"  -> TOKFY
 *   101 video_ultra  "Video IA — Ultra"      -> TOKFY
 *   qualquer outro                           -> SELLER.GLOBAL
 * Casa exatamente com os 317 pagantes confirmados
 * (subscriptions.plan_id IN (99,100,101) AND status='active' AND pagarme_status='paid').
 *
 * Casa por ID **e** por SLUG de proposito: se um dia clonarem o plano e o id
 * mudar, o slug (video_start/video_pro/video_ultra) ainda acerta; e se
 * renomearem o slug, o id ainda acerta. NAO usa `plans.category` porque
 * 'tt_shop' e compartilhado com planos 91/92/93, que sao seller.global.
 *
 * NAO criei coluna nova de marca: a regra abaixo e determinstica e verificavel
 * hoje; coluna nova exigiria backfill dos 317 + de todo historico, sem ganho.
 * Se a Tokfy ganhar plano proprio fora dessa faixa, o lugar de mexer e AQUI —
 * um arquivo so, nao 5 controllers.
 *
 * INF-030-MARCA (13/08) — CORRECAO IMPORTANTE sobre o paragrafo acima.
 * Medido no banco real: plan_id=100 tem 294 subscriptions, mas so 221 batem
 * com a lista de compradores Tokfy confirmados (cruzamento por e-mail contra
 * /root/tokfy-sem-acesso.json). As outras 73 sao clientes REAIS do
 * seller.global que compraram "Video IA — Ilimitado" pelo proprio site
 * seller.global (ex: rodrigo.oliveira.silva.1984@gmail.com, pagarme_status=
 * paid, 18/07). Ou seja: idForPlan()/TOKFY_PLAN_IDS NAO e mais confiavel como
 * fonte de verdade — e mantido so como FALLBACK de ultimo recurso (ver
 * fromRequest() e a coluna subscriptions.marca / clients.marca, que agora sao
 * a fonte de verdade real, escrita pelo CheckoutController a partir do Origin/
 * Referer da requisicao). NAO usar idForPlan()/forPlan() sozinho pra decisao
 * nova — so quando nao existir subscriptions.marca/clients.marca (registro
 * anterior a esta migration).
 */
final class BrandKit
{
    public const SELLERGLOBAL = 'sellerglobal';
    public const TOKFY        = 'tokfy';

    /** @var array<int> ids dos planos da Tokfy */
    public const TOKFY_PLAN_IDS = [99, 100, 101];

    /** @var array<string> slugs equivalentes (redundancia proposital) */
    public const TOKFY_PLAN_SLUGS = ['video_start', 'video_pro', 'video_ultra'];

    /**
     * Marca de um plano. Aceita o Model Plan, stdClass do DB::table, array,
     * ou o proprio id — pra servir qualquer caminho do codigo sem adaptador.
     *
     * @param mixed $plan
     */
    public static function idForPlan($plan): string
    {
        if ($plan === null) {
            return self::SELLERGLOBAL;
        }

        if (is_int($plan) || (is_string($plan) && ctype_digit($plan))) {
            return in_array((int) $plan, self::TOKFY_PLAN_IDS, true)
                ? self::TOKFY
                : self::SELLERGLOBAL;
        }

        $id   = null;
        $slug = null;

        if (is_array($plan)) {
            $id   = $plan['id']   ?? null;
            $slug = $plan['slug'] ?? null;
        } elseif (is_object($plan)) {
            $id   = $plan->id   ?? null;
            $slug = $plan->slug ?? null;
        }

        if ($id !== null && in_array((int) $id, self::TOKFY_PLAN_IDS, true)) {
            return self::TOKFY;
        }

        if ($slug !== null && in_array(strtolower(trim((string) $slug)), self::TOKFY_PLAN_SLUGS, true)) {
            return self::TOKFY;
        }

        return self::SELLERGLOBAL;
    }

    /** @param mixed $plan */
    public static function isTokfy($plan): bool
    {
        return self::idForPlan($plan) === self::TOKFY;
    }

    /**
     * INF-030-MARCA (13/08) — marca a partir do Origin/Referer da requisicao
     * HTTP. E o MESMO sinal que UserObserver::created() ja usa (desde
     * FIX-MISTURA-TOKFY 11/08) pra decidir se dispara o welcome de cadastro —
     * aqui e so centralizado num lugar reutilizavel pelo checkout tambem.
     *
     * Por que Origin/Referer e nao um campo enviado pelo front: funciona HOJE
     * sem esperar redeploy de nenhum dos dois frontends (seller.global e
     * tokfy.io ja mandam Origin em toda chamada cross-origin pro backend —
     * comprovado batendo em /api/checkout/plans com Origin forjado e vendo o
     * `access-control-allow-origin` da resposta refletir exatamente o valor
     * enviado, prova de que o middleware de CORS do Laravel LE esse header de
     * verdade pra cada request real). O CORS deste backend (config/cors.php)
     * so libera seller.global e tokfy.io pra esse fluxo — qualquer outra
     * origem e anomalia e cai no default historico (SELLERGLOBAL).
     *
     * @param \Illuminate\Http\Request $request
     */
    public static function fromRequest($request): string
    {
        $origin  = (string) $request->header('origin', '');
        $referer = (string) $request->header('referer', '');
        $sinal   = strtolower($origin . ' ' . $referer);

        if (str_contains($sinal, 'tokfy')) {
            return self::TOKFY;
        }

        return self::SELLERGLOBAL;
    }

    /** Confere se a string e uma das marcas conhecidas (evita salvar lixo). */
    public static function isValid(?string $marca): bool
    {
        return in_array($marca, [self::SELLERGLOBAL, self::TOKFY], true);
    }

    /**
     * Configuracao completa da marca. Espelha src/config/brand.ts.
     *
     * @return array<string,mixed>
     */
    public static function config(string $brandId): array
    {
        $mapa = [
            self::SELLERGLOBAL => [
                'id'                 => self::SELLERGLOBAL,
                'name'               => 'Seller Global',
                'domain'             => 'seller.global',
                'site_url'           => 'https://seller.global',
                'login_url'          => 'https://seller.global/login',
                'reset_url'          => 'https://seller.global/reset-password',
                'accent_hex'         => '#D7FF60',
                'accent_hex2'        => null,
                'cta_gradient'       => null,
                'support_agent_name' => 'Gabriel',
                'support_email'      => 'suporte@seller.global',
                'copyright_name'     => 'Seller Global',
                // null = mailer padrao (o de hoje). NAO mexer: qualquer mudanca
                // aqui altera o e-mail que ja funciona pro seller.global.
                'mailer'             => null,
                'from_address'       => null,   // cai em config('mail.from.address')
                'from_name'          => 'Seller Global',
                'view_html'          => 'emails.seller-welcome',
                'view_text'          => null,   // hoje o seller so tem HTML
            ],
            self::TOKFY => [
                'id'                 => self::TOKFY,
                'name'               => 'Tokfy',
                'domain'             => 'tokfy.io',
                'site_url'           => 'https://tokfy.io',
                'login_url'          => 'https://tokfy.io/login',
                'reset_url'          => 'https://tokfy.io/reset-password',
                'accent_hex'         => '#FF0050',
                'accent_hex2'        => '#00F2EA',
                'cta_gradient'       => 'linear-gradient(90deg, #FF0050, #00F2EA)',
                'support_agent_name' => 'Nina',
                'support_email'      => 'suporte@tokfy.io',
                'copyright_name'     => 'Tokfy',
                // Remetente PROPRIO: sair de gabriel@seller.global entregaria a
                // outra marca no cabecalho do e-mail. tokfy.io tem SPF
                // (ip4:66.94.100.155) e DKIM (*@tokfy.io) proprios no servidor.
                'mailer'             => 'smtp_tokfy',
                'from_address'       => null,   // cai em config('mail.tokfy_from.address')
                'from_name'          => 'Tokfy',
                'view_html'          => 'emails.tokfy-welcome',
                'view_text'          => 'emails.tokfy-welcome-text',
            ],
        ];

        return $mapa[$brandId] ?? $mapa[self::SELLERGLOBAL];
    }

    /**
     * Atalho: marca ja resolvida a partir do plano.
     *
     * @param  mixed $plan
     * @return array<string,mixed>
     */
    public static function forPlan($plan): array
    {
        return self::config(self::idForPlan($plan));
    }

    /** Assunto do e-mail de acesso liberado, por marca. */
    public static function accessSubject(string $brandId, string $planName): string
    {
        if ($brandId === self::TOKFY) {
            return '✅ Seu acesso à Tokfy está liberado — ' . $planName;
        }

        return '✅ Plano ' . $planName . ' ativado - Seller Global';
    }

    /** Remetente (endereco, nome) efetivo da marca. @return array{0:string,1:string} */
    public static function fromAddress(string $brandId): array
    {
        if ($brandId === self::TOKFY) {
            return [
                (string) config('mail.tokfy_from.address', 'suporte@tokfy.io'),
                (string) config('mail.tokfy_from.name', 'Tokfy'),
            ];
        }

        return [
            (string) config('mail.from.address'),
            'Seller Global',
        ];
    }
}
