<?php

namespace App\Http\Controllers\Email;

use App\Enums\EmailStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackEmailController extends Controller
{
    /**
     * Domínios cujos links podem ser redirecionados pelo tracker de cliques.
     * Inicializado no construtor pois usa config() (não pode ser const em PHP).
     *
     * @var array<string>
     */
    private array $allowedDomains;

    public function __construct()
    {
        $this->allowedDomains = array_filter([
            parse_url(config('app.frontend_url'), PHP_URL_HOST),
            'hubai.club',
            parse_url(config('app.url'), PHP_URL_HOST),
        ]);
    }

    /**
     * Registra abertura do e-mail e retorna pixel GIF 1x1 transparente.
     */
    public function open(string $token): Response
    {
        $log = EmailLog::where('token', $token)->first();

        if ($log) {
            $log->increment('opened_count');

            if (! $log->opened_at) {
                $log->update([
                    'opened_at' => now(),
                    'status'    => EmailStatus::Opened->value,
                ]);
            }
        }

        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Registra clique no link e redireciona para a URL original (whitelist obrigatória).
     */
    public function click(string $token, Request $request): \Illuminate\Http\RedirectResponse
    {
        $url    = (string) $request->query('url', '');
        $parsed = parse_url($url);
        $host   = $parsed['host'] ?? '';

        if (! $this->isAllowedHost($host)) {
            abort(403, 'Link não permitido.');
        }

        $log = EmailLog::where('token', $token)->first();

        if ($log) {
            $log->increment('click_count');

            if (! $log->clicked_at) {
                $log->update([
                    'clicked_at'   => now(),
                    'clicked_link' => $url,
                    'status'       => EmailStatus::Clicked->value,
                ]);
            }
        }

        return redirect($url);
    }

    private function isAllowedHost(string $host): bool
    {
        foreach ($this->allowedDomains as $domain) {
            if ($domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }
}
