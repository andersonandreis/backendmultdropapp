<?php

namespace App\Services\Integrations;

/**
 * SEL-422 — o que dizer ao cliente quando a conta de marketplace está travada.
 *
 * Ruan, 30/07, testando a conta de um cliente: "a mensagem de KYC tem que
 * aparecer em Integrações e outros lugares, até notificação na tela dele, para
 * ele arrumar. E na mensagem mandar o link para redirecionar ele."
 *
 * POR QUE O TEXTO MORA AQUI, E NÃO NA TELA:
 * o aviso precisa sair igual em Integrações, na notificação e em qualquer lugar
 * novo que apareça. Texto escrito na tela vira três textos diferentes na terceira
 * tela — e aí o cliente lê uma coisa num lugar e outra no outro. Aqui é um só.
 *
 * REGRA DO TEXTO: o cliente lê o que FAZER, nunca o código do erro. Nada de
 * "kyc_pendente" na cara dele. Cada mensagem diz três coisas, nesta ordem:
 * o que está errado, qual a consequência, e o link de onde resolver.
 *
 * O link do KYC aponta pro Seller Center da Shopee, que é FORA daqui. Mandar pra
 * nossa tela de reconectar seria pior que não avisar: o token está válido, então
 * ele reconectaria com sucesso e continuaria sem entrar pedido nenhum.
 */
class PendenciaContaService
{
    /**
     * Cadastro de vendedor incompleto na Shopee.
     *
     * As DUAS grafias existem em produção porque duas partes do ShopeeService
     * gravaram cada uma a sua (FOR-066 em inglês, SEL-397 em português). A
     * canônica passa a ser a portuguesa — é a que está no banco hoje — mas a
     * leitura aceita as duas pra sempre, senão registro antigo fica invisível
     * de novo. Reconhecer as duas custa nada; deixar de fora custou uma conta
     * de cliente parada por 7 dias.
     */
    public const KYC = ['kyc_pendente', 'kyc_pending'];

    public const KYC_CANONICO = 'kyc_pendente';

    /** Conta conectada mas sem canal de envio configurado — não fecha pedido. */
    public const SEM_ENVIO = ['no_shipping_channel'];

    /** Token caiu: aqui sim o caminho é reconectar no nosso painel. */
    public const RECONECTAR = ['needs_reauth', 'expired', 'disconnected'];

    /** Todos os status que travam a conta e que o cliente precisa ver. */
    public static function statusQueTravam(): array
    {
        return array_merge(self::KYC, self::SEM_ENVIO, self::RECONECTAR);
    }

    public static function ehKyc(?string $status): bool
    {
        return in_array((string) $status, self::KYC, true);
    }

    /**
     * Descreve a pendência pro cliente.
     *
     * @return array{motivo:string, titulo:string, mensagem:string, acao_url:string|null, acao_label:string|null, externo:bool}
     */
    public static function descrever(?string $status, string $plataforma, ?int $accountId = null): array
    {
        $painel = rtrim((string) config('app.frontend_url', ''), '/');
        $loja   = self::nomeDaPlataforma($plataforma);

        if (self::ehKyc($status)) {
            return [
                'motivo'     => 'cadastro_vendedor_incompleto',
                'titulo'     => "Cadastro de vendedor incompleto na {$loja}",
                'mensagem'   => "Seu cadastro de vendedor na {$loja} está incompleto. "
                              . 'Enquanto isso, seus pedidos não entram.',
                'acao_url'   => 'https://seller.shopee.com.br/',
                'acao_label' => "Concluir na {$loja}",
                'externo'    => true,
            ];
        }

        if (in_array((string) $status, self::SEM_ENVIO, true)) {
            return [
                'motivo'     => 'sem_canal_de_envio',
                'titulo'     => "Nenhuma forma de envio ativa na {$loja}",
                'mensagem'   => "Sua loja na {$loja} está sem forma de envio ativa. "
                              . 'Enquanto isso, seus pedidos não entram.',
                'acao_url'   => 'https://seller.shopee.com.br/',
                'acao_label' => "Ativar envio na {$loja}",
                'externo'    => true,
            ];
        }

        // Reconexão e qualquer outro caso: resolve no nosso painel mesmo.
        $url = $painel !== '' ? $painel . '/integracoes' : null;
        if ($url && $accountId) {
            $url .= '?reauth=' . $plataforma . '&account=' . $accountId;
        }

        return [
            'motivo'     => 'precisa_reconectar',
            'titulo'     => "Conexão com a {$loja} expirou",
            'mensagem'   => "A conexão da sua conta {$loja} expirou. "
                          . 'Enquanto isso, seus pedidos não entram.',
            'acao_url'   => $url,
            'acao_label' => "Reconectar {$loja}",
            'externo'    => false,
        ];
    }

    private static function nomeDaPlataforma(string $plataforma): string
    {
        return match ($plataforma) {
            'shopee'        => 'Shopee',
            'mercadolivre'  => 'Mercado Livre',
            'bling'         => 'Bling',
            'tiktok'        => 'TikTok Shop',
            default         => ucfirst($plataforma),
        };
    }
}
