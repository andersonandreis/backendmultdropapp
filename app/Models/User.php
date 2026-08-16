<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'avatar',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
        'cpf',
        'full_name',
        'birth_date',
        'rg',
        'rg_issuer',
        'mother_name',
        'father_name',
        'rg_front_file',
        'rg_back_file',
        'residence_proof_file',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // SEL-182 pentest fix: nunca serializar secret/backup codes 2FA em respostas JSON
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_backup_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function supplier()
    {
        return $this->hasOne(Supplier::class);
    }

    public function client()
    {
        return $this->hasOne(Client::class)->oldestOfMany();
    }

    /**
     * MUL-312: fonte unica de "esta conta pode acessar?".
     *
     * Regra do Ruan (31/07/2026): ativo acessa, inativo nao acessa, bloqueado nao
     * acessa. Antes disso a regra estava copiada em cinco lugares -- e o login pelo
     * Google checava so users.is_active, entao cliente inativo entrava por ali.
     *
     * Precedencia: bloqueado ganha de inativo, igual ao filtro da lista de clientes.
     *
     * @return string|null null = pode entrar. String = mensagem do motivo.
     */
    public function motivoSemAcesso(): ?string
    {
        if (! $this->is_active) {
            return 'Conta inativa. Entre em contato com o suporte.';
        }

        if ($this->role === 'client' && $this->client) {
            if ($this->client->blocked_at) {
                return 'Conta bloqueada. Entre em contato com o suporte.';
            }
            if (! $this->client->is_active) {
                return 'Conta inativa. Entre em contato com o suporte.';
            }
        }

        return null;
    }

    /**
     * SEL-XXX (10/08, pedido Ruan) — anti-revenda: 1 sessao ativa por vez no
     * painel principal, SO pra clientes (role=client). Cliente que loga num
     * 2o aparelho derruba o 1o no proximo request (o mais recente vence) --
     * impede compartilhar/revender o acesso e ficar girando video infinito
     * em varios aparelhos ao mesmo tempo.
     *
     * Admin/super_admin/supplier ficam de FORA de proposito: o Ruan usa admin
     * em varios aparelhos e nao pode se autodeslogar.
     *
     * Escopo dos tokens revogados: os de entrada do CLIENTE -- 'api-token'
     * (email/senha, Google e pos-2FA, ver AuthController), 'phone-auth'
     * (login por telefone, ver PhoneAuthController) e 'extensao-live' (extensao
     * de live shopping, LiveController::login) -- tratando os 4 canais como A
     * MESMA sessao (Google no aparelho B derruba o email/senha E a extensao de
     * live do aparelho A). Confirmado com o Ruan em 10/08: a extensao TAMBEM
     * deve cair na troca de aparelho (antes deixava ela de fora por seguranca
     * -- o comentario original em LiveController::login, "da pra revogar so
     * ele, sem derrubar o painel", so dizia que dava pra fazer separado, nao
     * que DEVIA ficar imune). NAO mexe em 'impersonation-token' (token do
     * ADMIN vendo como cliente via ImpersonationController -- nao e sessao do
     * cliente, entao fica de fora tanto pelo escopo original quanto por ser
     * do admin).
     *
     * Investigado antes de aplicar: a esteira de geracao de video (GenerateAvatarJob,
     * KlingBrowserGenerateJob, StudioLongVideoJob, AiVideoPipelineJob,
     * RefillAvatarPoolJob) NAO usa token Sanctum nenhum -- roda como job
     * interno via Eloquent direto, sem round-trip HTTP com Bearer token. O
     * pool multi-conta do Flow tambem nao depende de token de usuario client
     * -- e sessao Google do navegador (Playwright/browser-worker), fora do
     * Sanctum por completo. Chamar isto aqui e seguro pro worker de video.
     */
    public function revokeOtherPanelSessions(): void
    {
        if ($this->role !== 'client') {
            return;
        }

        // SEL-MULTIDISPOSITIVO (13/08, pedido do Ruan: "liberar ele de acessar em
        // 2 aparelhos para eu poder testar").
        //
        // O anti-revenda derruba a sessao anterior a CADA login de cliente, o que
        // torna impossivel testar em dois aparelhos ao mesmo tempo — e foi o que
        // derrubou a sessao do proprio Ruan no meio do teste de hoje.
        //
        // Em vez de afrouxar a regra pra todo mundo (o que reabriria a revenda de
        // acesso que ela existe pra impedir), a liberacao e por USUARIO e mora no
        // BANCO: settings group='security', key='multi_dispositivo_user_ids',
        // com uma lista JSON de ids. Assim da pra liberar e revogar sem deploy.
        try {
            $raw = \Illuminate\Support\Facades\DB::table('settings')
                ->where('group', 'security')
                ->where('key', 'multi_dispositivo_user_ids')
                ->value('value');
            $liberados = $raw ? (json_decode($raw, true) ?: []) : [];
            if (in_array((int) $this->id, array_map('intval', $liberados), true)) {
                \Illuminate\Support\Facades\Log::info('[anti-revenda] usuario liberado pra multiplos aparelhos — nao derrubei a sessao anterior', [
                    'user_id' => $this->id,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            // se a consulta falhar, mantem o comportamento ANTIGO (mais restritivo)
            \Illuminate\Support\Facades\Log::warning('[anti-revenda] nao consegui ler a lista de liberados, aplicando a regra padrao', [
                'erro' => $e->getMessage(),
            ]);
        }

        $this->tokens()->whereIn('name', ['api-token', 'phone-auth', 'extensao-live'])->delete();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return in_array($this->role, ['super_admin', 'supplier']);
        }

        if ($panel->getId() === 'app') {
            return $this->role === 'client';
        }

        return false;
    }
}
