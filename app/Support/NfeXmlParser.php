<?php

namespace App\Support;

/**
 * MUL-455: extrai os dados da NF-e direto do XML — o arquivo e a fonte da verdade.
 * Regex proposital (o XML da NF-e usa tags sem prefixo de namespace); SimpleXML
 * quebraria com XMLs levemente fora do padrao que os ERPs geram.
 */
class NfeXmlParser
{
    /** @return array{numero: string, serie: ?string, chave: string, emissao: ?string}|null */
    public static function extrair(string $xml): ?array
    {
        if ($xml === '' || strlen($xml) > 1024 * 1024) {
            return null;
        }

        // chave: protNFe/chNFe (autorizada) tem precedencia; senao o Id da infNFe
        $chave = null;
        if (preg_match('/<chNFe>\s*(\d{44})\s*<\/chNFe>/', $xml, $m)) {
            $chave = $m[1];
        } elseif (preg_match('/Id="NFe(\d{44})"/', $xml, $m)) {
            $chave = $m[1];
        }

        $numero  = preg_match('/<nNF>\s*(\d+)\s*<\/nNF>/', $xml, $m) ? $m[1] : null;
        $serie   = preg_match('/<serie>\s*(\d+)\s*<\/serie>/', $xml, $m) ? $m[1] : null;
        $emissao = null;
        if (preg_match('/<dhEmi>\s*([^<\s]+)\s*<\/dhEmi>/', $xml, $m)) {
            $ts = strtotime($m[1]);
            $emissao = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        if (! $numero || ! $chave) {
            return null;
        }

        return ['numero' => $numero, 'serie' => $serie, 'chave' => $chave, 'emissao' => $emissao];
    }
}
