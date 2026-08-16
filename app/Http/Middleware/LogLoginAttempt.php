<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEL-LOGINLOG (13/08, ordem do Ruan: "faz o que esta pendente").
 *
 * POR QUE ISTO EXISTE — o buraco medido hoje: o access_log do LiteSpeed
 * (2,3MB) tinha `grep -c ' POST '` = **ZERO**. Nenhuma requisicao POST da API
 * era registrada em lugar nenhum. Nao existia UMA linha de log de login, de
 * senha errada ou de 429 — nem hoje, nem em nenhum dia anterior. Quando o
 * proprio Ruan e o cliente wandernunes bateram em "Too Many Attempts", a
 * resposta honesta pra "quantas pessoas estao passando por isso?" era
 * "nao sei". E isso que este middleware conserta.
 *
 * ORDEM IMPORTA (2a armadilha, medida): por middleware DE ROTA nao funciona.
 * O Laravel tem prioridade propria de middleware e roda o ThrottleRequests
 * ANTES de um alias custom — a requisicao bloqueada nem chegava aqui (provado:
 * 25 tentativas, 20 gravadas, 5 sumiram). Por isso ele foi pro GRUPO api, com
 * prependToGroup, que e mais externo que qualquer middleware de rota.
 *
 * ⚠️ ARMADILHA JA MEDIDA (1a versao deste arquivo caiu nela): o
 * ThrottleRequests NAO devolve uma resposta 429 — ele LANCA
 * ThrottleRequestsException. Se o log ficar so depois de `$next($request)`,
 * ele nunca roda justamente no caso que a gente queria contar. Provado com
 * 23 tentativas: 20 gravaram, as 3 bloqueadas sumiram. Por isso o `$next`
 * vem dentro de try/catch, o log acontece nos DOIS caminhos, e a excecao e
 * relancada intacta (o cliente continua recebendo o 429 normal).
 *
 * NAO grava senha, nunca — so e-mail, ip, status e navegador.
 */
class LogLoginAttempt
{
    /** Caminhos de autenticacao que valem log. O resto da API passa direto. */
    private const ROTAS = ['api/login', 'api/google-login', 'api/register'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->path(), self::ROTAS, true)) {
            return $next($request);
        }

        $inicio = microtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $this->registrar($request, $status, $inicio);
            throw $e; // o cliente recebe exatamente o que receberia sem este log
        }

        $this->registrar($request, $response->getStatusCode(), $inicio);

        return $response;
    }

    private function registrar(Request $request, int $status, float $inicio): void
    {
        try {
            $resultado = match (true) {
                $status === 200 || $status === 201 => 'ok',
                $status === 422                    => 'credencial_invalida',
                $status === 429                    => 'BLOQUEADO_MUITAS_TENTATIVAS',
                $status === 403                    => 'proibido',
                $status >= 500                     => 'erro_servidor',
                default                            => 'http_' . $status,
            };

            $linha = sprintf(
                "%s\t%s\t%s\t%s\t%s\t%dms\t%s\n",
                now()->format('Y-m-d H:i:s'),
                $request->ip() ?: '-',
                $resultado,
                $status,
                (string) ($request->input('email') ?: '-'),
                (int) round((microtime(true) - $inicio) * 1000),
                substr((string) $request->userAgent(), 0, 120)
            );

            @file_put_contents(
                storage_path('logs/login-attempts-' . now()->format('Y-m-d') . '.log'),
                $linha,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // Log nunca pode derrubar login.
        }
    }
}
