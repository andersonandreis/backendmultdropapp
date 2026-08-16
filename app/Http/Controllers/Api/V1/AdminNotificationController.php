<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-329: broadcast por e-mail (admin -> user selecionado ou lista).
 * Templates pré-prontos + edição livre. Fica em /admin/settings tab=notifications.
 * Sem Mailable class — usa Mail::html com HTML inline pra evitar migration/view.
 */
class AdminNotificationController extends Controller
{
    public const TEMPLATES = [
        'general' => [
            'label'     => 'Mensagem livre',
            'subject'   => 'Uma mensagem da equipe Seller.Global',
            'body_html' => '<p>Olá {name},</p><p>[Digite sua mensagem aqui]</p>',
        ],
        'video_ready' => [
            'label'     => 'Vídeo pronto',
            'subject'   => 'Seu vídeo está pronto no Seller.Global',
            'body_html' => '<p>Oi {name},</p>'
                . '<p>Boa notícia — seu vídeo terminou de renderizar e já está na sua Galeria!</p>'
                . '<p style="margin-top:20px;"><a href="https://seller.global/tokfy?tab=galeria" '
                . 'style="display:inline-block;background:#d7ff60;color:#1C1C1B;padding:12px 24px;'
                . 'border-radius:8px;font-weight:bold;text-decoration:none;">Ver meu vídeo</a></p>',
        ],
        'credit_low' => [
            'label'     => 'Créditos acabando',
            'subject'   => 'Seus créditos IA estão acabando',
            'body_html' => '<p>Oi {name},</p>'
                . '<p>Seus créditos IA estão baixos. Adicione um novo pacote pra continuar '
                . 'gerando vídeos sem interrupção.</p>'
                . '<p style="margin-top:20px;"><a href="https://seller.global/carteira" '
                . 'style="display:inline-block;background:#d7ff60;color:#1C1C1B;padding:12px 24px;'
                . 'border-radius:8px;font-weight:bold;text-decoration:none;">Adicionar créditos</a></p>',
        ],
        'new_feature' => [
            'label'     => 'Novidade / atualização',
            'subject'   => 'Novidade no Seller.Global',
            'body_html' => '<p>Oi {name},</p>'
                . '<p>Temos uma novidade pra você: [descreva aqui].</p>'
                . '<p style="margin-top:20px;"><a href="https://seller.global" '
                . 'style="display:inline-block;background:#d7ff60;color:#1C1C1B;padding:12px 24px;'
                . 'border-radius:8px;font-weight:bold;text-decoration:none;">Ver agora</a></p>',
        ],
    ];

    /** GET /api/v1/admin/notifications/templates */
    public function templates(): JsonResponse
    {
        return response()->json(['templates' => self::TEMPLATES]);
    }

    /** GET /api/v1/admin/notifications/users?search=&per_page= */
    public function users(Request $r): JsonResponse
    {
        $q = User::query()->orderBy('id', 'desc');
        if ($s = trim((string) $r->query('search', ''))) {
            $q->where(function ($qq) use ($s) {
                $qq->where('email', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            });
        }
        if ($r->query('active_only') !== '0') {
            $q->where('is_active', 1);
        }
        $per = min(100, max(1, (int) $r->query('per_page', 30)));
        $users = $q->paginate($per, ['id', 'name', 'email', 'role', 'is_active']);
        return response()->json($users);
    }

    /** POST /api/v1/admin/notifications/send-email */
    public function sendEmail(Request $r): JsonResponse
    {
        $v = $r->validate([
            'user_ids'   => 'required|array|min:1|max:500',
            'user_ids.*' => 'integer',
            'subject'    => 'required|string|max:200',
            'body_html'  => 'required|string|max:20000',
        ]);
        $users = User::whereIn('id', $v['user_ids'])->whereNotNull('email')->get();
        $ok = 0; $fail = 0; $errors = [];
        foreach ($users as $u) {
            $name = $u->name ?: 'Cliente';
            $body = str_replace('{name}', e($name), $v['body_html']);
            $subject = str_replace('{name}', $name, $v['subject']);
            $html = self::wrap($subject, $body);
            try {
                Mail::html($html, function ($m) use ($u, $subject) {
                    $m->to($u->email, $u->name)->subject($subject);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $errors[] = ['user_id' => $u->id, 'email' => $u->email, 'err' => substr($e->getMessage(), 0, 200)];
                Log::warning('[Admin Broadcast] send fail', ['user_id' => $u->id, 'err' => $e->getMessage()]);
            }
        }
        return response()->json([
            'ok'            => $ok,
            'fail'          => $fail,
            'total_matched' => $users->count(),
            'errors'        => array_slice($errors, 0, 10),
        ]);
    }

    /** Wrap HTML do corpo com layout minimalista. */
    private static function wrap(string $subject, string $bodyHtml): string
    {
        $subj = e($subject);
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $subj . '</title></head>'
            . '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;'
            . 'background:#f4f6f9;margin:0;padding:32px 16px;color:#1a1a2e;">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;'
            . 'overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">'
            . '<div style="background:linear-gradient(135deg,#22d3a0 0%,#d7ff60 100%);'
            . 'padding:28px 40px;color:#1C1C1B;">'
            . '<h1 style="margin:0;font-size:22px;font-weight:900;letter-spacing:-0.02em;">Seller.Global</h1>'
            . '</div>'
            . '<div style="padding:36px 40px 28px;line-height:1.6;font-size:15px;color:#1a1a2e;">'
            . $bodyHtml
            . '</div>'
            . '<div style="background:#f8f9fc;border-top:1px solid #eaedf3;padding:20px 40px;'
            . 'text-align:center;color:#8a8fa8;font-size:12px;">'
            . 'Seller.Global · <a href="https://seller.global" style="color:#6c63ff;text-decoration:none;">seller.global</a>'
            . '</div></div></body></html>';
    }
}
