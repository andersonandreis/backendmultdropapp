<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-417 — que idioma o cliente pode escolher pro áudio do vídeo.
 *
 * Ruan, 30/07: "A gente vai deixar somente o idioma do Kling que funciona pro
 * cliente selecionar. (...) No meu, eu posso selecionar o português, mas bota
 * demo só pra mim. E pra galera é 'em breve'."
 *
 * O QUE ESTAVA ACONTECENDO (achado da auditoria, e é a raiz do áudio ruim):
 * não existia escolha de idioma nenhuma. O KlingBrowserService cravava pt-BR em
 * TODO prompt, via garanteIdiomaPtBr() — justamente o idioma em que o áudio
 * nativo do Kling sai macarrônico. Ou seja: o cliente não escolhia errado, o
 * sistema escolhia por ele, sempre, o pior caso.
 *
 * Esta classe é a fonte única da verdade: o backend decide por ela e o frontend
 * desenha a lista a partir do /v1/studio/languages. Ter a regra em dois lugares
 * é como se perde o controle — e "opção que só a tela esconde" não é bloqueio,
 * é enfeite: bastaria mandar o campo na request pra furar.
 */
class VideoLanguageService
{
    /** Idioma usado quando o cliente não escolhe nada. */
    public const PADRAO_CLIENTE = 'es-419';

    /** Português: liberado só pro super_admin, e marcado como demo. */
    public const PT = 'pt-BR';

    /**
     * Catálogo por usuário. `selecionavel` é o que vale — o frontend mostra os
     * não-selecionáveis desabilitados, com o `badge`, porque o Ruan quer que o
     * cliente VEJA que português vem aí, em vez de sumir com a opção.
     */
    public static function catalogo(?int $userId): array
    {
        $admin = self::ehSuperAdmin($userId);

        return [
            [
                'id'           => 'es-419',
                'label'        => 'Espanhol (América Latina)',
                'selecionavel' => true,
                'badge'        => null,
                'demo'         => false,
                'aviso'        => null,
            ],
            [
                'id'           => self::PT,
                'label'        => 'Português (Brasil)',
                'selecionavel' => $admin,
                'badge'        => $admin ? 'Demo' : 'Em breve',
                'demo'         => $admin,
                'aviso'        => $admin ? 'Demo — ainda estamos ajustando o áudio.' : null,
            ],
            [
                // SEL-453 (30/07): inglês liberado. O motor já narrava em inglês
                // (garanteIdioma tem o ramo en desde o SEL-417); faltava o roteiro,
                // construído agora em KaloclipStyleScriptService::promptsEn().
                'id'           => 'en',
                'label'        => 'Inglês (EUA)',
                'selecionavel' => true,
                'badge'        => null,
                'demo'         => false,
                'aviso'        => null,
            ],
        ];
    }

    /**
     * Decide o idioma que VAI valer de verdade na geração.
     *
     * Nunca confia no que veio na request: se pedirem um idioma que a conta não
     * pode usar, cai no padrão em vez de estourar. Motivo: isto roda no meio do
     * fluxo de geração, e derrubar o vídeo do cliente por causa de um campo de
     * idioma seria trocar um áudio ruim por nenhum vídeo.
     */
    public static function resolver(?int $userId, ?string $pedido): string
    {
        $pedido = trim((string) $pedido);
        if ($pedido === '') {
            return self::PADRAO_CLIENTE;
        }

        foreach (self::catalogo($userId) as $idioma) {
            if (strcasecmp($idioma['id'], $pedido) !== 0) {
                continue;
            }
            if ($idioma['selecionavel']) {
                return $idioma['id'];
            }
            Log::info('[SEL-417] idioma pedido nao liberado pra esta conta — caindo no padrao', [
                'user_id' => $userId, 'pedido' => $pedido, 'usado' => self::PADRAO_CLIENTE,
            ]);
            return self::PADRAO_CLIENTE;
        }

        Log::info('[SEL-417] idioma desconhecido — caindo no padrao', [
            'user_id' => $userId, 'pedido' => $pedido,
        ]);
        return self::PADRAO_CLIENTE;
    }

    /** O vídeo em português é demo (áudio ainda em ajuste) — a tela precisa dizer isso. */
    public static function ehDemo(?int $userId, string $lang): bool
    {
        return self::normaliza($lang) === self::PT && self::ehSuperAdmin($userId);
    }

    /**
     * Português NÃO usa o áudio nativo do Kling — é ele que sai macarrônico.
     * O caminho é: gera o vídeo, narra no ElevenLabs com a voz clonada e
     * sincroniza o lábio. Quem executa isso é o AiVideoPipelineJob, que já tem
     * runVoice() + runLipsync(). Esta função só responde "este idioma precisa
     * daquele caminho?".
     */
    public static function exigeNarracaoExterna(string $lang): bool
    {
        return self::normaliza($lang) === self::PT;
    }

    public static function normaliza(?string $lang): string
    {
        $l = strtolower(trim((string) $lang));
        if ($l === '') {
            return self::PADRAO_CLIENTE;
        }
        if (str_starts_with($l, 'pt')) {
            return self::PT;
        }
        if (str_starts_with($l, 'es')) {
            return 'es-419';
        }
        if (str_starts_with($l, 'en')) {
            return 'en';
        }
        return self::PADRAO_CLIENTE;
    }

    private static function ehSuperAdmin(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }
        try {
            return DB::table('users')->where('id', $userId)->value('role') === 'super_admin';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
