<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateManagerInvite;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEL-GERENTE (09/08) — Gerente de Afiliados.
 *
 * Um afiliado pode ser GERENTE ("guarda-chuva") e trazer outros afiliados
 * pra baixo dele (Affiliate.manager_id). O gerente ganha comissao OVERRIDE
 * RECORRENTE (manager_override_rate %) sobre TODA cobranca dos afiliados
 * dele — nao so a primeira. Afiliados sob um gerente entram com
 * video_gen_authorized=false e nao geram video ate o admin (ou o proprio
 * fluxo de aceite de convite, se decidido depois) liberar.
 *
 * Esse servico e chamado a partir dos DOIS pontos que hoje registram
 * AffiliateCommission (PagarmeWebhookController::createAffiliateCommission
 * e AffiliateCommissionService::registerPayment) — sem alterar a logica
 * de comissao normal, so ADICIONA a linha do override quando aplicavel.
 */
class AffiliateManagerService
{
    /**
     * SEL-GERENTE (decisao Ruan 09/08 tarde): taxa de comissao EFETIVA de um
     * afiliado quando ele esta SOB um gerente. O gerente reparte o PROPRIO
     * pool (manager_override_rate) com o afiliado — a comissao do afiliado
     * SAI do pool (divisao), NAO e um bonus por cima. Ex.: pool=50%,
     * gerente define manager_commission_rate=30% pro afiliado -> afiliado
     * ganha 30%, gerente fica com os 20% restantes de override.
     *
     * Retorna null se o afiliado NAO esta sob (nenhum) gerente valido — quem
     * chama deve, nesse caso, usar a taxa fixa antiga (30% upgrade / 10%
     * recorrente), comportamento intacto pra quem nao tem gerente.
     *
     * Default enquanto o gerente AINDA NAO configurou (manager_commission_rate
     * null): 0% pro afiliado, pool inteiro de override pro gerente. Escolhido
     * porque (a) o gerente e quem decide a divisao — ate ele decidir, nao faz
     * sentido conceder uma fatia sem acao explicita dele, e (b) um default
     * fixo (ex. manter os 30% antigos) poderia ultrapassar um pool menor que
     * isso, criando estado invalido (override negativo).
     *
     * Trava de seguranca: o valor salvo em manager_commission_rate nunca
     * ultrapassa o pool ATUAL do gerente, mesmo que o pool tenha sido
     * reduzido depois de configurado (evita override negativo).
     */
    public static function resolveSubAffiliateRate(Affiliate $affiliate): ?float
    {
        if (! $affiliate->manager_id) {
            return null; // sem gerente — comportamento antigo intacto
        }

        $manager = Affiliate::find($affiliate->manager_id);
        if (! $manager || ! $manager->is_manager) {
            return null;
        }

        $pool = (float) ($manager->manager_override_rate ?? 0);

        // SEL-SUBAFILIADO-NAO-TRABALHA-DE-GRACA (16/08): sem valor definido, o codigo
        // antigo devolvia 0.0 — o sub vendia e recebia NADA, menos do que receberia sem
        // gerente nenhum, e sem nenhum aviso na tela. Default agora e METADE do pool, que
        // e a leitura literal da regra ("desse 50% dele, quanto ele da pro afiliado").
        // Loga porque valor assumido por default precisa ser visivel, nao magico.
        if ($affiliate->manager_commission_rate === null) {
            $padrao = round($pool / 2, 2);
            \Illuminate\Support\Facades\Log::warning('[SEL-SUBAFILIADO] sub sem percentual definido — aplicando metade do pool', [
                'sub_id'     => $affiliate->id,
                'gerente_id' => $manager->id,
                'pool'       => $pool,
                'aplicado'   => $padrao,
            ]);

            return min(max($padrao, 0.0), $pool);
        }

        $rate = (float) $affiliate->manager_commission_rate;

        return min(max($rate, 0.0), $pool);
    }

    /**
     * Registra a comissao override do GERENTE sobre uma venda/cobranca de um
     * afiliado que esta sob ele. Roda em TODA cobranca (upgrade e recorrente),
     * sem limite de 6 — diferente do cap da comissao normal do afiliado.
     *
     * SEL-GERENTE (decisao Ruan 09/08 tarde): o override NAO e mais o pool
     * cheio — e o RESTANTE do pool depois de descontar o que o gerente deu
     * ao afiliado (resolveSubAffiliateRate). pool=50%, afiliado com 30% ->
     * override = 20%. Se o gerente deu o pool inteiro (ou mais, por alguma
     * inconsistencia), override = 0 e nada e criado.
     *
     * @param Affiliate $subAffiliate afiliado que gerou a venda (o indicado)
     * @param float     $grossAmount valor bruto da cobranca
     * @param string|null $planSlug
     * @param int|null  $subscriptionId
     * @param int|null  $referralId  referral do sub-afiliado (informativo, nao do gerente)
     * @param string    $sourceEventType 'upgrade'|'recurring' — so pra log/notes
     */
    public static function registerOverrideForSale(
        Affiliate $subAffiliate,
        float $grossAmount,
        ?string $planSlug,
        ?int $subscriptionId,
        ?int $referralId,
        string $sourceEventType = 'recurring'
    ): ?AffiliateCommission {
        try {
            if ($grossAmount <= 0) {
                return null;
            }
            if (! $subAffiliate->manager_id) {
                return null; // afiliado nao esta sob nenhum gerente
            }

            $manager = Affiliate::find($subAffiliate->manager_id);
            if (! $manager || ! $manager->is_manager) {
                return null;
            }
            if ($manager->approval_status !== 'approved' || $manager->status !== 'active') {
                return null; // gerente suspenso/nao aprovado nao acumula override
            }
            $poolRate = (float) ($manager->manager_override_rate ?? 0);
            if ($poolRate <= 0) {
                return null; // gerente sem taxa de override configurada
            }

            // SEL-GERENTE (decisao Ruan 09/08 tarde): divide o pool — override
            // = pool - comissao do afiliado (nunca negativo).
            $subRate = self::resolveSubAffiliateRate($subAffiliate) ?? 0.0;
            $rate    = max($poolRate - $subRate, 0.0);
            if ($rate <= 0) {
                return null; // gerente deu o pool inteiro pro afiliado — sem sobra de override
            }

            $amount = round($grossAmount * ($rate / 100), 2);
            if ($amount <= 0) {
                return null;
            }

            $override = AffiliateCommission::create([
                'affiliate_id'      => $manager->id,
                'referral_id'       => $referralId,
                'subscription_id'   => $subscriptionId,
                'gross_amount'      => $grossAmount,
                'commission_rate'   => $rate,
                'commission_amount' => $amount,
                'status'            => 'pending',
                'event_type'        => 'manager_override',
                'plan_slug'         => $planSlug,
                'notes'             => "Override recorrente ({$sourceEventType}) sobre venda do afiliado #{$subAffiliate->id} "
                    . '(' . $subAffiliate->display_name . ') — pool ' . $poolRate . '% - comissao dele ' . $subRate . '% = ' . $rate . '% pra voce',
            ]);

            $manager->increment('total_earned', $amount);

            Log::info('[SEL-GERENTE] override de gerente registrado (split do pool)', [
                'manager_affiliate_id' => $manager->id,
                'sub_affiliate_id'     => $subAffiliate->id,
                'gross'                => $grossAmount,
                'pool_rate'            => $poolRate,
                'sub_rate'             => $subRate,
                'override_rate'        => $rate,
                'amount'               => $amount,
                'event_type'           => $sourceEventType,
            ]);

            return $override;
        } catch (\Throwable $e) {
            Log::error('[SEL-GERENTE] falha ao registrar override de gerente', [
                'sub_affiliate_id' => $subAffiliate->id ?? null,
                'error'            => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // GATE de geracao de video
    // =========================================================================

    /**
     * Checa se o usuario logado pode gerar video. So bloqueia quando o
     * usuario TEM registro de afiliado, esse afiliado esta sob um gerente
     * (manager_id preenchido) E video_gen_authorized = false.
     * Qualquer outro caso (nao e afiliado, e afiliado direto, ou ja liberado)
     * = ok=true, PASSA RETO (nao interfere no fluxo de video normal).
     *
     * @return array{ok:bool, message?:string}
     */
    public static function checkVideoGenAllowed(?int $userId): array
    {
        if (! $userId) {
            return ['ok' => true];
        }

        try {
            $affiliate = Affiliate::where('user_id', $userId)->first();
            if (! $affiliate) {
                return ['ok' => true]; // nao e afiliado — gate nao se aplica
            }

            if ($affiliate->isBlockedFromVideoGen()) {
                return [
                    'ok'      => false,
                    'message' => 'Seu gerente ainda não liberou a geração de vídeo pra você.',
                ];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('[SEL-GERENTE] checkVideoGenAllowed falhou, liberando por seguranca', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return ['ok' => true]; // falha no gate NUNCA bloqueia video de quem nao e afiliado sob gerente
        }
    }

    // =========================================================================
    // Convite de gerente
    // =========================================================================

    public static function generateInviteToken(Affiliate $manager, ?int $createdByUserId = null, int $expiresInDays = 30): AffiliateManagerInvite
    {
        return AffiliateManagerInvite::create([
            'manager_affiliate_id' => $manager->id,
            'token'                => Str::random(40),
            'created_by_user_id'   => $createdByUserId,
            'expires_at'           => now()->addDays($expiresInDays),
        ]);
    }

    /**
     * Aceita um convite pra um usuario JA LOGADO. Se o usuario ainda nao e
     * afiliado, cria o registro (pending, igual ao fluxo normal de apply/register)
     * ja com manager_id + video_gen_authorized=false. Se ja e afiliado sem
     * gerente, so anexa ao gerente (nao mexe em approval_status/status ja
     * existentes).
     *
     * @return array{ok:bool, message:string, affiliate?:Affiliate, status:int}
     */
    public static function acceptInvite(string $token, User $user): array
    {
        $invite = AffiliateManagerInvite::where('token', $token)->first();
        if (! $invite) {
            return ['ok' => false, 'message' => 'Convite não encontrado.', 'status' => 404];
        }
        if (! $invite->isValid()) {
            return ['ok' => false, 'message' => $invite->isUsed() ? 'Convite já foi usado.' : 'Convite expirado.', 'status' => 410];
        }

        $manager = $invite->manager;
        if (! $manager || ! $manager->is_manager) {
            return ['ok' => false, 'message' => 'Convite inválido — gerente não encontrado ou desativado.', 'status' => 422];
        }

        $affiliate = Affiliate::where('user_id', $user->id)->first();

        if ($affiliate) {
            if ($affiliate->manager_id) {
                return ['ok' => false, 'message' => 'Você já está vinculado a um gerente.', 'status' => 409];
            }
            if ($affiliate->id === $manager->id) {
                return ['ok' => false, 'message' => 'Você não pode aceitar o próprio convite.', 'status' => 422];
            }
            $affiliate->update([
                'manager_id'           => $manager->id,
                'video_gen_authorized' => false,
            ]);
        } else {
            $affiliate = Affiliate::create([
                'user_id'               => $user->id,
                'referral_code'         => self::generateUniqueReferralCode(),
                'commission_rate'       => 30.00,
                'status'                => 'inactive',
                'approval_status'       => 'pending',
                'application_name'      => $user->name,
                'application_email'     => $user->email,
                'manager_id'            => $manager->id,
                'video_gen_authorized'  => false,
            ]);
        }

        $invite->update(['used_by_affiliate_id' => $affiliate->id, 'used_at' => now()]);

        Log::info('[SEL-GERENTE] convite aceito', [
            'invite_id'            => $invite->id,
            'manager_affiliate_id' => $manager->id,
            'affiliate_id'         => $affiliate->id,
            'user_id'              => $user->id,
        ]);

        return [
            'ok'        => true,
            'message'   => "Convite aceito! Você está sob o gerente {$manager->display_name}.",
            'affiliate' => $affiliate->fresh(),
            'status'    => 200,
        ];
    }

    public static function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
        } while (Affiliate::where('referral_code', $code)->exists());
        return $code;
    }
}
