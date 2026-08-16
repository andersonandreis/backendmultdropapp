<?php
namespace App\Observers;

use App\Models\Affiliate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-353 (Ruan 24/07 21:10): quando afiliado eh criado no admin,
 * dispara email pra pessoa com login+senha padrao (123456) + link admin
 * de afiliados. Reseta senha do user vinculado pra 123456 se novo cadastro.
 */
class AffiliateObserver
{
    public function created(Affiliate $affiliate): void
    {
        try {
            $user = $affiliate->user;
            if (!$user) return;

            // Se conta nao ativada, ativa + reseta senha padrao 123456
            $wasNew = !$user->is_active || empty($user->password);
            if ($wasNew) {
                $user->is_active = true;
                $user->password = bcrypt('123456');
                $user->save();
            }

            $firstName = explode(' ', trim($user->name))[0];
            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;color:#1C1C1B">'
                . '<div style="background:#D7FF60;padding:16px;border-radius:8px;margin-bottom:16px">'
                . '<p style="margin:0;font-weight:bold;font-size:14px;color:#1C1C1B">🎯 Voce agora e Afiliado Seller Global</p>'
                . '</div>'
                . '<h2>Oi ' . htmlspecialchars($firstName) . '!</h2>'
                . '<p>Seu cadastro como afiliado foi criado. Aqui esta seu acesso:</p>'
                . '<div style="background:#f6f6f6;border-radius:12px;padding:20px;margin:16px 0">'
                . '<p style="margin:0 0 8px;font-size:12px;color:#666">E-MAIL</p>'
                . '<p style="margin:0 0 16px;font-family:monospace;font-weight:bold">' . $user->email . '</p>'
                . ($wasNew ? '<p style="margin:0 0 8px;font-size:12px;color:#666">SENHA INICIAL</p><p style="margin:0;font-family:monospace;font-weight:900;font-size:22px;background:#D7FF60;padding:8px 16px;border-radius:6px;display:inline-block">123456</p>' : '<p style="margin:0;font-size:13px;color:#666">Voce ja tem senha cadastrada — usa a sua.</p>')
                . '</div>'
                . '<div style="background:#1C1C1B;color:#fff;padding:20px;border-radius:12px;margin:16px 0">'
                . '<p style="margin:0 0 12px;font-size:12px;color:#D7FF60;font-weight:bold">SEU LINK DE INDICACAO</p>'
                . '<p style="margin:0;font-family:monospace;font-size:16px;color:#D7FF60;word-break:break-all">https://seller.global/r/' . $affiliate->referral_code . '</p>'
                . '<p style="margin:12px 0 0;font-size:12px;opacity:0.8">Comissao: ' . rtrim(rtrim(number_format((float) $affiliate->commission_rate, 2, ',', '.'), '0'), ',') . '% sobre cada venda gerada</p>'
                . '</div>'
                . '<div style="text-align:center;margin:24px 0">'
                . '<a href="https://seller.global/afiliado/dashboard" style="display:inline-block;background:#D7FF60;color:#1C1C1B;padding:14px 28px;border-radius:10px;text-decoration:none;font-weight:900">Acessar Painel de Afiliado →</a>'
                . '</div>'
                . '<p style="font-size:12px;color:#999">Duvidas? Chama no WhatsApp que a gente te ajuda.</p>'
                . '<p style="margin-top:24px">Um abraco,<br><b>Gabriel</b><br>Time Seller Global</p></div>';

            Mail::html($html, function($m) use ($user) {
                $m->to($user->email, $user->name)->subject('🎯 Voce agora e afiliado Seller Global — seu acesso');
            });
            Log::info('[SEL-353] AffiliateObserver: email enviado', ['aff' => $affiliate->id, 'user' => $user->email]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-353] AffiliateObserver falha', ['aff' => $affiliate->id, 'err' => $e->getMessage()]);
        }
    }
}
