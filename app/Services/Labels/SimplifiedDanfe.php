<?php

namespace App\Services\Labels;

/**
 * NOV-208 — DANFE Simplificado (modelo etiqueta 10x15, ref. UpSeller/Bling)
 * gerado a partir do XML da NF-e autorizada (API fiscal ML).
 *
 * Sem dependência externa: barcode Code 128C da chave de acesso gerado
 * como SVG inline (tabela oficial de padrões embutida).
 */
class SimplifiedDanfe
{
    /**
     * Padrões Code 128 (sequência de larguras barra/espaço), valores 0-106.
     * Fonte: ISO/IEC 15417. Stop (106) tem 7 elementos.
     */
    private const C128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213',
        '122312', '132212', '221213', '221312', '231212', '112232', '122132',
        '122231', '113222', '123122', '123221', '223211', '221132', '221231',
        '213212', '223112', '312131', '311222', '321122', '321221', '312212',
        '322112', '322211', '212123', '212321', '232121', '111323', '131123',
        '131321', '112313', '132113', '132311', '211313', '231113', '231311',
        '112133', '112331', '132131', '113123', '113321', '133121', '313121',
        '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111',
        '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114',
        '413111', '241112', '134111', '111242', '121142', '121241', '114212',
        '124112', '124211', '411212', '421112', '421211', '212141', '214121',
        '412121', '111143', '111341', '131141', '114113', '114311', '411113',
        '411311', '113141', '114131', '311141', '411131', '211412', '211214',
        '211232', '2331112',
    ];

    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * Extrai do XML da NF-e os dados do DANFE simplificado.
     * Retorna null se o XML não parsear ou faltar bloco essencial.
     */
    public function dataFromXml(string $absPath): ?array
    {
        if (!is_file($absPath)) {
            return null;
        }
        $doc = @simplexml_load_file($absPath);
        if (!$doc) {
            return null;
        }
        $doc->registerXPathNamespace('n', self::NFE_NS);

        $inf = $doc->xpath('//n:NFe/n:infNFe')[0] ?? null;
        if (!$inf) {
            return null;
        }
        $inf->registerXPathNamespace('n', self::NFE_NS);

        $get = function (\SimpleXMLElement $el, string $path): ?string {
            $el->registerXPathNamespace('n', self::NFE_NS);
            $hit = $el->xpath($path)[0] ?? null;
            return $hit !== null ? trim((string) $hit) : null;
        };

        $chave = preg_replace('/^NFe/', '', (string) $inf['Id']);
        if (strlen($chave) !== 44 || !ctype_digit($chave)) {
            return null;
        }

        $itens = [];
        foreach ($inf->xpath('n:det') as $det) {
            $det->registerXPathNamespace('n', self::NFE_NS);
            $itens[] = [
                'descricao' => $get($det, 'n:prod/n:xProd') ?? '',
                'qtd'       => (float) ($get($det, 'n:prod/n:qCom') ?? 0),
                'valor'     => (float) ($get($det, 'n:prod/n:vProd') ?? 0),
            ];
        }

        $emitDoc = $get($inf, 'n:emit/n:CNPJ') ?? $get($inf, 'n:emit/n:CPF');
        $destDoc = $get($inf, 'n:dest/n:CNPJ') ?? $get($inf, 'n:dest/n:CPF');

        return [
            'chave'           => $chave,
            'chave_formatada' => trim(chunk_split($chave, 4, ' ')),
            'numero'          => $get($inf, 'n:ide/n:nNF'),
            'serie'           => $get($inf, 'n:ide/n:serie'),
            'emissao'         => $this->fmtDate($get($inf, 'n:ide/n:dhEmi')),
            'tipo'            => $get($inf, 'n:ide/n:tpNF') === '0' ? '0 - Entrada' : '1 - Saída',
            'protocolo'       => $get($doc, '//n:protNFe/n:infProt/n:nProt'),
            'protocolo_data'  => $this->fmtDate($get($doc, '//n:protNFe/n:infProt/n:dhRecbto')),
            'emit_nome'       => $get($inf, 'n:emit/n:xNome'),
            'emit_doc'        => $this->fmtDoc($emitDoc),
            'emit_ie'         => $get($inf, 'n:emit/n:IE'),
            'emit_uf'         => $get($inf, 'n:emit/n:enderEmit/n:UF'),
            'dest_nome'       => $get($inf, 'n:dest/n:xNome'),
            'dest_doc'        => $this->fmtDoc($destDoc),
            'dest_endereco'   => $this->endereco($inf, $get),
            'total'           => (float) ($get($inf, 'n:total/n:ICMSTot/n:vNF') ?? 0),
            'itens'           => $itens,
            'barcode_svg'     => $this->barcodeSvg($chave),
        ];
    }

    /**
     * Barcode Code 128C (só dígitos, quantidade par) como SVG inline.
     * preserveAspectRatio=none: o CSS define a largura final; altura fixa.
     */
    public function barcodeSvg(string $digits): string
    {
        if (!ctype_digit($digits) || strlen($digits) % 2 !== 0) {
            return '';
        }

        $values = [105]; // Start C
        foreach (str_split($digits, 2) as $pair) {
            $values[] = (int) $pair;
        }
        $sum = 105;
        foreach ($values as $i => $v) {
            if ($i > 0) {
                $sum += $v * $i;
            }
        }
        $values[] = $sum % 103;
        $values[] = 106; // Stop

        $quiet = 10;
        $rects = '';
        $x     = $quiet;
        foreach ($values as $v) {
            $isBar = true;
            foreach (str_split(self::C128[$v]) as $w) {
                if ($isBar) {
                    $rects .= sprintf('<rect x="%d" width="%d" y="0" height="1"/>', $x, (int) $w);
                }
                $x += (int) $w;
                $isBar = !$isBar;
            }
        }
        $total = $x + $quiet;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total
            . ' 1" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000">'
            . $rects . '</svg>';
    }

    private function fmtDate(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function fmtDoc(?string $doc): ?string
    {
        if (!$doc) {
            return null;
        }
        if (strlen($doc) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $doc);
        }
        if (strlen($doc) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $doc);
        }
        return $doc;
    }

    private function endereco(\SimpleXMLElement $inf, callable $get): string
    {
        $p = fn (string $path) => $get($inf, "n:dest/n:enderDest/n:{$path}");
        $linha = trim(($p('xLgr') ?? '') . ', ' . ($p('nro') ?? ''), ', ');
        $partes = array_filter([
            $linha,
            $p('xBairro'),
            trim(($p('xMun') ?? '') . '/' . ($p('UF') ?? ''), '/'),
            $p('CEP') ? 'CEP ' . preg_replace('/(\d{5})(\d{3})/', '$1-$2', $p('CEP')) : null,
        ]);
        return implode(' - ', $partes);
    }
}
