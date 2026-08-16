<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuario autenticado com role=client possui um registro Client.
 *
 * Caso o Client não exista (usuário criado antes do Observer ou via import),
 * cria on-demand com defaults seguros e registra warning para investigação.
 * Idempotente — usa firstOrCreate.
 */
class EnsureClientExists
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'client' && ! $user->relationLoaded('client')) {
            $user->load('client');
        }

        if ($user && $user->role === 'client' && ! $user->client) {
            Log::warning('EnsureClientExists: Client ausente — criando on-demand', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'path'    => $request->path(),
            ]);

            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = Client::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'is_active'    => true,
                    'listing_mode' => 'auto',
                    // INF-030-MARCA (13/08): grava a marca JA no nascimento do client.
                    // Antes so o CheckoutController gravava — quem chegava pelo
                    // CADASTRO (sem comprar) nascia com marca NULA. Medido em 13/08:
                    // 14 clients criados no mesmo dia, TODOS sem marca e sem assinatura.
                    // Marca nula faz o SellerWelcomeMail cair no palpite por plano, e
                    // o palpite manda quem tem plano de Video IA pra tokfy.io — ou seja,
                    // cliente do seller.global receberia e-mail de acesso de OUTRA marca
                    // no dia em que comprasse. Mesma funcao que o checkout usa, pra nao
                    // existirem duas regras de marca no sistema.
                    'marca'        => \App\Support\BrandKit::fromRequest($request),
                ]
            );

            // Atualiza relação em memória para evitar nova query no controller
            $user->setRelation('client', $client);
        }

        return $next($request);
    }
}
