<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\InviteTrialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-CONVITE Fase B — painel admin do trial fechado (/convite).
 *
 *  GET  /api/v1/admin/convite/stats          — modo, teto, oferta, contadores
 *  PUT  /api/v1/admin/convite/settings       — liga/desliga (mode), teto, oferta
 *  POST /api/v1/admin/convite/release-batch  — libera N da waitlist (e-mail SMTP)
 *
 * Só admin/super_admin (grupo de rota). Toggle e teto vivem em settings(group=convite),
 * lidos pelo ConviteController::start em tempo real — mudar aqui muda o /convite na hora.
 */
class AdminConviteController extends Controller
{
    private const INVITE_URL = 'https://seller.global/convite';

    public function stats(Request $request)
    {
        return response()->json([
            'mode'      => InviteTrialService::mode(),
            'daily_cap' => InviteTrialService::dailyCap(),
            'offer'     => InviteTrialService::offer(),
            'waitlist'  => [
                'waiting'   => (int) DB::table('convite_waitlist')->where('status', 'waiting')->count(),
                'notified'  => (int) DB::table('convite_waitlist')->where('status', 'notified')->count(),
                'converted' => (int) DB::table('convite_waitlist')->where('status', 'converted')->count(),
                'total'     => (int) DB::table('convite_waitlist')->count(),
            ],
            'trials'    => [
                'active' => (int) DB::table('trial_invites')->where('status', 'active')->where('expires_at', '>', now())->count(),
                'today'  => (int) DB::table('trial_invites')->whereNotNull('video_used_at')->whereDate('video_used_at', today())->count(),
                'total'  => (int) DB::table('trial_invites')->count(),
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $v = $request->validate([
            'mode'        => 'nullable|in:open,waitlist',
            'daily_cap'   => 'nullable|integer|min:0|max:100000',
            'offer'       => 'nullable|array',
            'offer.label' => 'nullable|string|max:120',
            'offer.price' => 'nullable|string|max:40',
            'offer.url'   => 'nullable|string|max:300',
        ]);

        if (array_key_exists('mode', $v) && $v['mode'] !== null) {
            $this->putSetting('mode', $v['mode']);
        }
        if (array_key_exists('daily_cap', $v) && $v['daily_cap'] !== null) {
            $this->putSetting('daily_cap', (string) $v['daily_cap']);
        }
        if (! empty($v['offer'])) {
            $current = InviteTrialService::offer();
            $merged  = array_merge($current, array_filter($v['offer'], fn ($x) => $x !== null));
            $this->putSetting('offer_json', json_encode($merged));
        }

        Log::info('[SEL-CONVITE] admin atualizou settings', ['by' => $request->user()?->id, 'payload' => $v]);

        return $this->stats($request);
    }

    public function releaseBatch(Request $request)
    {
        $v = $request->validate(['n' => 'required|integer|min:1|max:1000']);

        $rows = DB::table('convite_waitlist')
            ->where('status', 'waiting')
            ->orderBy('id')
            ->limit((int) $v['n'])
            ->get(['id', 'email']);

        if ($rows->isEmpty()) {
            return response()->json(['sent' => 0, 'failed' => 0, 'message' => 'Ninguém aguardando na lista.']);
        }

        $batchId = 'batch_' . now()->format('YmdHis');
        $sent = 0; $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->enviarConvite($row->email);
                DB::table('convite_waitlist')->where('id', $row->id)->update([
                    'status'      => 'notified',
                    'notified_at' => now(),
                    'batch_id'    => $batchId,
                    'updated_at'  => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[SEL-CONVITE] falha ao enviar convite da waitlist', [
                    'email' => $row->email, 'err' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SEL-CONVITE] lote liberado', ['batch' => $batchId, 'sent' => $sent, 'failed' => $failed, 'by' => $request->user()?->id]);

        return response()->json([
            'sent'     => $sent,
            'failed'   => $failed,
            'batch_id' => $batchId,
            'message'  => "Lote enviado: {$sent} e-mail(s)" . ($failed ? ", {$failed} falha(s)" : '') . '.',
        ]);
    }

    private function putSetting(string $key, string $value): void
    {
        $exists = DB::table('settings')->where('group', 'convite')->where('key', $key)->exists();
        if ($exists) {
            DB::table('settings')->where('group', 'convite')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);
        } else {
            DB::table('settings')->insert(['group' => 'convite', 'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function enviarConvite(string $email): void
    {
        $url  = self::INVITE_URL;
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;background:#0a0a0a;color:#e5e5e5;padding:32px;border-radius:16px;max-width:520px;margin:auto">'
              . '<h1 style="color:#fff;font-size:22px;margin:0 0 8px">Sua vaga no teste abriu! 🎉</h1>'
              . '<p style="color:#a3a3a3;font-size:14px;line-height:1.6;margin:0 0 20px">Você estava na lista de espera do teste gratuito do <b style="color:#D7FF60">seller.global</b>. Abriu uma vaga pra você — é só entrar e criar seu primeiro vídeo que vende.</p>'
              . '<a href="' . $url . '" style="display:inline-block;background:#D7FF60;color:#0a0a0a;font-weight:bold;font-size:15px;text-decoration:none;padding:14px 28px;border-radius:12px">Começar meu teste grátis</a>'
              . '<p style="color:#737373;font-size:12px;margin:20px 0 0">Ou acesse: <a href="' . $url . '" style="color:#D7FF60">' . $url . '</a></p>'
              . '</div>';

        Mail::html($html, function ($m) use ($email) {
            $m->to($email)->subject('Sua vaga no teste do seller.global abriu 🎉');
        });
    }
}
