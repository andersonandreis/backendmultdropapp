<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/login',
        summary: 'Autenticar usuario e obter Bearer token',
        description: 'Recebe email e senha, valida as credenciais e retorna um token Sanctum para uso nos demais endpoints protegidos. O token deve ser enviado no header Authorization: Bearer {token}.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        format: 'email',
                        description: 'E-mail cadastrado do lojista',
                        example: 'lojista@hubai.io'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        format: 'password',
                        description: 'Senha do usuario',
                        example: 'senha123'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login realizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'token',
                            type: 'string',
                            description: 'Token Sanctum para uso no header Authorization',
                            example: '1|abc123defghijklmnop'
                        ),
                        new OA\Property(
                            property: 'token_type',
                            type: 'string',
                            description: 'Tipo do token',
                            example: 'Bearer'
                        ),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 42),
                                new OA\Property(property: 'name', type: 'string', example: 'Joao Silva'),
                                new OA\Property(property: 'email', type: 'string', example: 'lojista@hubai.io'),
                                new OA\Property(
                                    property: 'role',
                                    type: 'string',
                                    description: 'Papel do usuario: admin, client, supplier',
                                    example: 'client'
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Credenciais invalidas ou campos ausentes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Credenciais invalidas.'),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['email' => ['Credenciais invalidas.']]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $authOk = Auth::attempt($request->only('email', 'password'));

        if (! $authOk) {
            // Legacy fallback for lojistas migrated with random bcrypt passwords
            $userForLegacy = \App\Models\User::where('email', $request->email)->first();
            $clientForLegacy = $userForLegacy?->client;
            $legacyIdLogin  = $clientForLegacy?->legacy_id_login ?? null;
            $legacyPassType = $clientForLegacy?->legacy_password_type ?? null;

            if ($legacyIdLogin && $legacyPassType !== 'plaintext_migrated') {
                try {
                    $legacyResp = \Illuminate\Support\Facades\Http::timeout(8)
                        ->withHeaders(['Referer' => 'https://hubai.io/'])
                        ->withOptions(['allow_redirects' => false])
                        ->asJson()
                        ->post('https://goolhub.io/api/app2/auth/auth_login.php', [
                            'email' => $request->email,
                            'senha' => $request->password,
                        ]);
                    $legacyBody = $legacyResp->json();
                    if (!empty($legacyBody['success']) && !empty($legacyBody['token'])) {
                        // Rehash with bcrypt and mark as migrated
                        $userForLegacy->password = \Illuminate\Support\Facades\Hash::make($request->password);
                        $clientForLegacy->legacy_password_type = 'plaintext_migrated';
                        $userForLegacy->save();
                        $clientForLegacy->save();
                        Auth::login($userForLegacy);
                        $authOk = true;
                    }
                } catch (\Throwable $e) {
                    // Legacy service unavailable — fall through to error
                }
            }
        }

        if (! $authOk) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais invalidas.'],
            ]);
        }

        $user = Auth::user();

        // MUL-312: a regra de acesso mora em User::motivoSemAcesso(), nao aqui.
        if ($motivo = $user->motivoSemAcesso()) {
            Auth::logout();
            return response()->json(['message' => $motivo], 403);
        }

        // MUL-222: 2FA login challenge — se user tem 2FA confirmado, retorna challenge_token em vez de token real
        if ($user->two_factor_confirmed_at) {
            $challengeToken = bin2hex(random_bytes(32));
            \Illuminate\Support\Facades\Cache::put(
                'twofa_challenge:' . $challengeToken,
                ['user_id' => $user->id, 'email' => $user->email],
                now()->addMinutes(5)
            );
            Auth::logout();
            return response()->json([
                'require_2fa'     => true,
                'challenge_token' => $challengeToken,
                'expires_in'      => 300,
            ]);
        }

        // SEL-XXX (10/08): anti-revenda -- 1 sessao ativa por vez pra role=client
        // (ver User::revokeOtherPanelSessions). No-op pra admin/supplier.
        $user->revokeOtherPanelSessions();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ])->header(
            'Set-Cookie',
            config('app.session_cookie_name', 'app_token') . '=' . rawurlencode($token) . '; Max-Age=2592000; Path=/; Domain=' . config('app.session_cookie_domain', '') . '; Secure; HttpOnly; SameSite=Lax'
        );
    }

    // MUL-214 pos: expõe config pública NECESSÁRIA pro front (google client_id NÃO é segredo — é ID público OAuth).
    // Cache 5min pra reduzir load. NUNCA expor secrets aqui.
    public function publicConfig()
    {
        return \Illuminate\Support\Facades\Cache::remember('public_config', 300, function () {
            $gid = config('services.google.client_id');
            if (! $gid) {
                try {
                    $dbClient = \DB::table('settings')->where('key', 'google_client_id')->value('value');
                    if ($dbClient) { $gid = decrypt($dbClient); }
                } catch (\Throwable $e) { $gid = null; }
            }
            return response()->json([
                'google_client_id' => $gid ?: null,
            ]);
        });
    }

    // MUL-214 item 36: login via Google (front usa GSI SDK e envia id_token JWT)
    public function googleLogin(Request $request)
    {
        $validated = $request->validate(['id_token' => 'required|string']);
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(6)
                ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $validated['id_token']]);
            if (! $resp->ok()) {
                throw ValidationException::withMessages(['id_token' => ['Token do Google inválido.']]);
            }
            $payload = $resp->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['message' => 'Não foi possível validar o Google agora.'], 503);
        }
        // MUL-214 pos: aceita client_id do settings table (admin configura via UI) OU env
        $expectedAud = config('services.google.client_id');
        if (! $expectedAud) {
            try {
                $dbClient = \DB::table('settings')->where('key', 'google_client_id')->value('value');
                if ($dbClient) { $expectedAud = decrypt($dbClient); }
            } catch (\Throwable $e) { /* ignora */ }
        }
        if ($expectedAud && ($payload['aud'] ?? null) !== $expectedAud) {
            throw ValidationException::withMessages(['id_token' => ['Google client_id não bate.']]);
        }
        $email = strtolower($payload['email'] ?? '');
        if (! $email || empty($payload['email_verified'])) {
            throw ValidationException::withMessages(['id_token' => ['E-mail Google não verificado.']]);
        }
        $user = \App\Models\User::where('email', $email)->first();
        if (! $user) {
            $user = \App\Models\User::create([
                'name'     => $payload['name'] ?? explode('@', $email)[0],
                'email'    => $email,
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'role'     => 'client',
                'is_active'=> 1,
                'email_verified_at' => now(),
            ]);
        } else if ($motivo = $user->motivoSemAcesso()) {
            // MUL-312: antes olhava so users.is_active -- cliente inativo ou
            // bloqueado entrava pelo Google sem nenhuma checagem do client.
            return response()->json(['message' => $motivo], 403);
        }
        Auth::login($user);
        if ($user->two_factor_confirmed_at) {
            $challengeToken = bin2hex(random_bytes(32));
            \Illuminate\Support\Facades\Cache::put('twofa_challenge:' . $challengeToken,
                ['user_id' => $user->id, 'email' => $user->email],
                now()->addMinutes(5)
            );
            Auth::logout();
            return response()->json(['require_2fa' => true, 'challenge_token' => $challengeToken, 'expires_in' => 300]);
        }
        // SEL-XXX (10/08): anti-revenda -- mesma sessao unica do login por senha
        // (ver User::revokeOtherPanelSessions) -- login por Google no aparelho B
        // derruba o email/senha do aparelho A.
        $user->revokeOtherPanelSessions();
        $token = $user->createToken('api-token')->plainTextToken;
        return response()->json([
            'token' => $token, 'token_type' => 'Bearer',
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ]);
    }

    // MUL-222: verifica code 2FA e emite token real
    public function verify2fa(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => 'required|string',
            'code'            => 'required|string|min:6|max:12',
        ]);
        $payload = \Illuminate\Support\Facades\Cache::get('twofa_challenge:' . $validated['challenge_token']);
        if (!$payload || empty($payload['user_id'])) {
            throw ValidationException::withMessages(['challenge_token' => ['Sessão de 2FA expirada. Faça login novamente.']]);
        }
        $user = \App\Models\User::find($payload['user_id']);
        if (!$user || !$user->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => ['2FA desativado para este usuário.']]);
        }
        $code = trim(str_replace(' ', '', $validated['code']));
        $verified = false;
        try {
            $secret = decrypt($user->two_factor_secret);
            if (\App\Services\TotpService::verifyCode($secret, $code)) $verified = true;
        } catch (\Throwable $e) {}
        if (!$verified && $user->two_factor_backup_codes) {
            try {
                $codes = json_decode(decrypt($user->two_factor_backup_codes), true) ?: [];
                $upperCode = strtoupper($code);
                if (in_array($upperCode, array_map('strtoupper', $codes), true)) {
                    $verified = true;
                    $remaining = array_values(array_filter($codes, fn($c) => strtoupper($c) !== $upperCode));
                    \DB::table('users')->where('id', $user->id)->update([
                        'two_factor_backup_codes' => encrypt(json_encode($remaining)),
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {}
        }
        if (!$verified) {
            throw ValidationException::withMessages(['code' => ['Código inválido.']]);
        }
        \Illuminate\Support\Facades\Cache::forget('twofa_challenge:' . $validated['challenge_token']);
        Auth::login($user);
        // SEL-XXX (10/08): anti-revenda -- mesmo o token emitido pos-2FA respeita
        // a sessao unica (ver User::revokeOtherPanelSessions).
        $user->revokeOtherPanelSessions();
        $token = $user->createToken('api-token')->plainTextToken;
        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ]);
    }


    #[OA\Post(
        path: '/api/logout',
        summary: 'Revogar token atual do usuario autenticado',
        description: 'Invalida o token Sanctum enviado no header Authorization. Apos esse endpoint, o token nao pode mais ser usado para autenticar requisicoes.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout realizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Token revogado com sucesso.'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                    ]
                )
            ),
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revogado com sucesso.']);
    }

    public function register(Request $request)
    {
        // MUL-269 fase 2: company_name deixou de existir em clients (nome vem do
        // user conectado). Validacao mantida como campo opcional pra compat com
        // fronts antigos, mas ignorada abaixo (nao ha destino).
        $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:users,email',
            'password'     => 'required|string|min:8|confirmed',
            'document'     => 'nullable|string|max:20',
            'phone'        => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
        ]);

        // Email duplicado: retornar 409 explícito (validation já cobre, mas garante resposta clara)
        if (\App\Models\User::where('email', $request->email)->exists()) {
            return response()->json(['message' => 'E-mail ja cadastrado.'], 409);
        }

        $user = \App\Models\User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => $request->password, // cast 'hashed' no modelo
            'role'      => 'client',
            'is_active' => true,
        ]);

        // SEL-166: push admin "toca a caixa" no cadastro novo (som caixa
        // registradora, R$297 constante = demo mode do SW). Try/catch dentro.
        \App\Services\RegisterPushNotifier::notifyRegister($user->id);

        // O UserObserver::created cria o Client via firstOrCreate automaticamente.
        // Complementar campos adicionais que o Observer nao tem (document, phone).
        // MUL-269 fase 2: company_name removido de clients — nome vem do user.
        if ($user->client) {
            $user->client->update([
                'document'     => $request->document ?? '',
                'phone'        => $request->phone,
                'is_active'    => true,
            ]);
        }

        // SEL-345: Atribuir referral de afiliado se cookie presente
        $this->trackAffiliateSignup($request, $user);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 201);
    }

    /**
     * SEL-345: Vincula signup ao afiliado via cookie ou query param ref.
     */
    private function trackAffiliateSignup(\Illuminate\Http\Request $request, \App\Models\User $user): void
    {
        try {
            $refCode = $request->cookie('affiliate_ref') ?? $request->query('ref');
            if (! $refCode) {
                return;
            }

            // SEL-492 (31/07): o /r/{code} aceita TANTO o codigo gerado quanto o
            // slug personalizado (custom_referral_slug) e grava o que veio no
            // cookie. Aqui so se procurava por referral_code -- entao link
            // personalizado registrava o clique, o cadastro nao achava afiliado
            // nenhum e voltava CALADO: sem indicacao, sem comissao, sem erro.
            // Ninguem tem slug hoje (0 de 5), entao nao houve perda -- mas o dia
            // que alguem personalizar o link, ele para de pagar em silencio.
            $affiliate = \App\Models\Affiliate::where('approval_status', 'approved')
                ->where(function ($q) use ($refCode) {
                    $q->where('referral_code', strtoupper($refCode))
                      ->orWhereRaw('UPPER(custom_referral_slug) = ?', [strtoupper($refCode)]);
                })
                ->first();

            if (! $affiliate) {
                return;
            }

            // Nao vincular afiliado a si mesmo
            if ($affiliate->user_id === $user->id) {
                return;
            }

            $client = $user->client;
            if (! $client) {
                return;
            }

            // Evitar duplicata
            if (\App\Models\AffiliateReferral::where('referred_client_id', $client->id)->exists()) {
                return;
            }

            // Atualizar referral existente (clique sem client) ou criar novo
            $existing = \App\Models\AffiliateReferral::where('affiliate_id', $affiliate->id)
                ->whereNull('referred_client_id')
                ->where('referral_code', strtoupper($refCode))
                ->where('attributed_at', '>=', now()->subDays(60))
                ->latest()
                ->first();

            if ($existing) {
                $existing->update([
                    'referred_client_id' => $client->id,
                    'status'             => 'pending',
                ]);
            } else {
                \App\Models\AffiliateReferral::create([
                    'affiliate_id'       => $affiliate->id,
                    'referred_client_id' => $client->id,
                    'referral_code'      => strtoupper($refCode),
                    'ip_address'         => $request->ip(),
                    'user_agent'         => $request->userAgent(),
                    'attributed_at'      => now(),
                    'status'             => 'pending',
                ]);
            }

            \Illuminate\Support\Facades\Log::info('[SEL-345] Referral atribuido no signup', [
                'affiliate_id' => $affiliate->id,
                'client_id'    => $client->id,
                'ref_code'     => strtoupper($refCode),
            ]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEL-345] trackAffiliateSignup failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SEL-082 F1 — Cadastro tier tiktok_free (funil sem cartao).
     *
     * Cria user + client + subscription active com plan.slug=tiktok_free.
     * Middleware RestrictFreeAccess bloqueia rotas fora da allowlist.
     * POST /api/register-tiktok-free
     */
    public function registerTikTokFree(\Illuminate\Http\Request $request)
    {
        // SEL-398: 1.129 pessoas tomaram 422 em ingles ("The email has already been
        // taken.") ao tentar se cadastrar com e-mail que ja tinham. Ninguem entendia
        // que bastava fazer login — o funil de entrada perdia todo mundo ai.
        $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:users,email',
            'password'     => 'required|string|min:8',
            'phone'        => 'nullable|string|max:20',
            'document'     => 'nullable|string|max:20',
            'fingerprint'  => 'nullable|array',
        ], [
            'name.required'     => 'Informe seu nome.',
            'email.required'    => 'Informe seu e-mail.',
            'email.email'       => 'Esse e-mail nao parece valido. Confira e tente de novo.',
            'email.unique'      => 'Voce ja tem conta com esse e-mail. Faca login em seller.global/login — se esqueceu a senha, use "Esqueci minha senha".',
            'password.required' => 'Crie uma senha.',
            'password.min'      => 'A senha precisa ter pelo menos 8 caracteres.',
        ]);

        if (\App\Models\User::where('email', $request->email)->exists()) {
            return response()->json(['message' => 'E-mail ja cadastrado.'], 409);
        }

        $fpService = app(\App\Services\FingerprintService::class);
        $analysis = $fpService->analyze($request, (array) $request->input('fingerprint', []));
        if ($analysis['score'] >= 70) {
            $fpService->persist(null, $analysis);
            return response()->json([
                'message' => 'Multiplas contas do mesmo dispositivo detectadas. Contate suporte.',
                'flags' => $analysis['flags'],
            ], 429);
        }

        $trialPlan = \App\Models\Plan::where('slug', 'tt_shop_trial_3d')->first();
        $tiktokFreePlan = $trialPlan ?: \App\Models\Plan::where('slug', 'tiktok_free')->first();
        if (! $tiktokFreePlan) {
            return response()->json(['error' => 'tiktok_free_plan_missing'], 500);
        }

        $user = \App\Models\User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => 'client',
            'is_active' => true,
        ]);

        // SEL-166: push admin no cadastro tiktok_free tambem (mesmo funil de lead).
        \App\Services\RegisterPushNotifier::notifyRegister($user->id);

        // MUL-269 fase 2: company_name removido de clients — nome vem do user.
        if ($user->client) {
            $user->client->update([
                'document'     => $request->document ?? '',
                'phone'        => $request->phone,
                'is_active'    => true,
            ]);
        }

        $subData = [
            'client_id'            => $user->client->id,
            'plan_id'              => $tiktokFreePlan->id,
            'status'               => ($tiktokFreePlan->trial_days > 0 ? 'trialing' : 'active'),
            'payment_method'       => null,
            'current_period_start' => now(),
            'current_period_end'   => now()->addYears(5),
            'needs_verification'   => ($analysis['score'] >= 30 && $analysis['score'] < 70),
        ];
        if ($tiktokFreePlan->trial_days > 0) {
            $subData['trial_ends_at'] = now()->addDays($tiktokFreePlan->trial_days);
        }
        \App\Models\Subscription::create($subData);

        $fpService->persist($user->id, $analysis);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token'       => $token,
            'token_type'  => 'Bearer',
            'user'        => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'access_tier' => 'tiktok_free',
        ], 201);
    }

    /**
     * SEL-184: "esqueci minha senha" — envia link de redefinição por e-mail.
     * Resposta sempre 200 pra não enumerar contas existentes.
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);
            $frontend = rtrim(config('app.frontend_url'), '/');
            $url = $frontend.'/reset-password?token='.$token.'&email='.urlencode($user->email);
            $appName = config('app.name', 'Seller Global');
            $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px">'
                .'<h2 style="margin:0 0 16px">Redefinição de senha</h2>'
                .'<p>Olá, '.e($user->name ?: '').'!</p>'
                .'<p>Recebemos um pedido pra redefinir a senha da sua conta no '.e($appName).'. Clique no botão abaixo (link válido por 60 minutos):</p>'
                .'<p style="margin:24px 0"><a href="'.$url.'" style="background:#D7FF60;color:#1C1C1B;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold">Redefinir minha senha</a></p>'
                .'<p style="font-size:12px;color:#666">Se você não pediu a redefinição, ignore este e-mail — nada será alterado.</p>'
                .'</div>';
            try {
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($user, $appName) {
                    $m->to($user->email)->subject('Redefinição de senha — '.$appName);
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('SEL-184 forgotPassword mail fail: '.$e->getMessage());
            }
        }

        return response()->json(['message' => 'Se o e-mail existir, enviamos o link de redefinição.']);
    }

    /**
     * SEL-184: consome o token do e-mail e define a nova senha.
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:6|max:64',
        ]);

        $status = \Illuminate\Support\Facades\Password::broker()->reset(
            [
                'email'                 => $data['email'],
                'token'                 => $data['token'],
                'password'              => $data['password'],
                'password_confirmation' => $data['password'],
            ],
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
                // derruba tokens antigos — sessões velhas não sobrevivem à troca
                $user->tokens()->delete();
            }
        );

        if ($status !== \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Link inválido ou expirado. Peça um novo link de redefinição.'], 422);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso. Faça login com a nova senha.']);
    }
}
