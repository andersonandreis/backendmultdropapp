<?php

namespace App\Observers;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    /**
     * Dispara o email de boas-vindas e garante a existência do perfil Client
     * após a criação de um novo usuário com role=client.
     *
     * Idempotente: usa firstOrCreate para não duplicar em caso de retry.
     */
    public function created(User $user): void
    {
        if ($user->role === 'client') {
            if (filled($user->email)) {
                // FIX-MISTURA-TOKFY 11/08 (Ruan): cadastro vindo do tokfy.io NAO
                // recebe o welcome da Seller Global (cliente Tokfy recebia e-mail
                // da marca errada — ex user 2996 imnotedu706). Detecta pela
                // Origin/Referer do request. Quando houver mailbox/template Tokfy,
                // trocar a supressao por um welcome Tokfy proprio.
                $origem = (string) (request()?->headers?->get('origin')) . ' '
                        . (string) (request()?->headers?->get('referer'));

                // ── SEL-MARCADESCONHECIDA (13/08) — na duvida, NAO manda.
                //
                // A regra antiga era "se NAO for tokfy, manda o welcome da Seller".
                // Isso tem um buraco: cadastro criado por SCRIPT (CLI, importacao,
                // provisionamento) nao tem request nenhum — logo `$origem` vem vazia,
                // "nao e tokfy" da verdadeiro, e um cliente TOKFY provisionado por
                // script recebia "Seus dados de acesso ao Seller.Global".
                //
                // E o mesmo erro que quase mandou o e-mail de acesso errado pros 317
                // pagantes Tokfy hoje: assumir a marca em vez de reconhecer.
                //
                // Agora a marca precisa ser RECONHECIDA, nao presumida:
                //   origem diz seller.global -> manda o welcome da Seller
                //   origem diz tokfy         -> suprime (ver pendencia abaixo)
                //   origem desconhecida      -> SUPRIME e registra
                //
                // Silencio e melhor que marca errada: um cliente sem e-mail de
                // boas-vindas a gente conserta; um cliente Tokfy recebendo
                // correspondencia de outra empresa, nao.
                //
                // PENDENCIA REAL (nao e este ticket): nao existe welcome de CADASTRO
                // com a marca Tokfy — so o de ACESSO LIBERADO (SellerWelcomeMail, ja
                // bimarca via App\Support\BrandKit). Enquanto nao existir, cadastro
                // pelo tokfy.io fica sem e-mail de boas-vindas. Quando existir, trocar
                // a supressao pelo envio da versao certa.
                $ehTokfy  = stripos($origem, 'tokfy') !== false;
                $ehSeller = stripos($origem, 'seller.global') !== false
                         || stripos($origem, 'sellerglobal') !== false;

                if ($ehSeller && ! $ehTokfy) {
                    SendWelcomeEmailJob::dispatch($user)->delay(now()->addSeconds(30));
                } else {
                    Log::info('[SEL-MARCADESCONHECIDA] welcome da Seller NAO enviado', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                        'motivo'  => $ehTokfy ? 'cadastro veio do tokfy.io'
                                              : 'marca da origem desconhecida (provavel cadastro por script)',
                        'origem'  => trim($origem) !== '' ? mb_substr(trim($origem), 0, 120) : '(sem request)',
                    ]);
                }
            }

            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            Client::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'is_active'    => true,
                    'listing_mode' => 'auto',
                ]
            );
        }
    }
}
