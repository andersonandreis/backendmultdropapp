<!DOCTYPE html>
<html lang="pt-BR">
@php $layout = $layout ?? 'sequence'; @endphp
<head>
    <meta charset="UTF-8">
    <title>Etiqueta combinada — Pedidos</title>
    <style>
        /* NOV-096 — Etiqueta combinada (cabecalho HubAI + etiqueta marketplace).
           Porta o modelo legado /imprimir_etiquetas_curso.php para o NovoHubAI.
           NOV-208 — dois layouts:
             sequence: Zebra 100mm x 150mm, etiqueta e nota em folhas seguidas
             side:     folha unica paisagem (A4 landscape), etiqueta + nota lado a lado
             footer:   Zebra 100mm x 150mm, nota compacta no rodape (padrao Shopee) */
        @if ($layout === 'side')
        @page {
            size: 297mm 210mm;
            margin: 0;
        }
        @else
        @page {
            size: 100mm 150mm;
            margin: 0;
        }
        @endif

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            background: #fff;
            font-size: 11px;
        }

        .etiqueta {
            /* MUL-440: a folha tinha 2mm de padding em volta de tudo, e a etiqueta da
               Shopee ja traz a propria margem. Duas folgas somadas deixavam o codigo
               de barras pequeno demais. O respiro do texto passou para o cabecalho. */
            width: 100mm;
            height: 150mm;
            padding: 0;
            page-break-after: always;
            page-break-inside: avoid;
            position: relative;
            overflow: hidden;
        }
        .etiqueta:last-child { page-break-after: auto; }

        .cabecalho {
            width: 100%;
            border-bottom: 1px dashed #000;
            /* MUL-440: respiro do texto vive aqui agora */
            padding: 1.5mm 2mm;
            margin-bottom: 1.5mm;
            /* MUL-439: teto duro. Sem isto, titulo grande (ou pedido com varios itens)
               empurrava o cabecalho para baixo e roubava area da etiqueta do
               marketplace -- que e a parte que a transportadora le. O cabecalho e
               apoio para a separacao; se faltar espaco, quem corta e ele. */
            max-height: 26mm;
            overflow: hidden;
        }

        .cab-linha { display: table; width: 100%; table-layout: fixed; }

        .cab-produtos { display: table-cell; vertical-align: top; }

        .cabecalho-meta {
            display: table-cell;
            width: 40mm;
            vertical-align: top;
            padding-left: 2mm;
            text-align: right;
            font-size: 8px;
            color: #333;
            line-height: 1.3;
        }

        .cabecalho-meta strong { color: #000; }

        .produto-row {
            display: table;
            width: 100%;
            margin-top: 1mm;
        }

        .produto-foto {
            display: table-cell;
            width: 12mm;
            vertical-align: middle;
            text-align: center;
        }

        .produto-foto img {
            max-width: 11mm;
            max-height: 11mm;
            border: 1px solid #ccc;
        }

        .produto-foto .placeholder {
            width: 11mm;
            height: 11mm;
            background: #f3f3f3;
            border: 1px dashed #aaa;
            display: inline-block;
            line-height: 11mm;
            text-align: center;
            font-size: 8px;
            color: #777;
        }

        .produto-info {
            display: table-cell;
            vertical-align: middle;
            padding-left: 2mm;
        }

        .produto-qtd {
            display: inline-block;
            background: #000;
            color: #fff;
            font-weight: bold;
            /* MUL-439: 14px -> 8px, o MESMO tamanho do nome do produto (pedido do
               Ruan, 19/08). O destaque fica por conta do negrito e do fundo preto,
               nao do tamanho -- que era o que empurrava o cabecalho. */
            font-size: 8px;
            /* MUL-439: o badge tinha 2mm de folga de cada lado com fonte de 8px --
               a caixa preta ficava tres vezes maior que o texto dentro dela. */
            padding: 0 0.8mm;
            border-radius: 1mm;
            line-height: 1.25;
            margin-right: 0.8mm;
        }

        .produto-sku {
            /* MUL-439: 12px -> 8px, igual ao nome do produto. Negrito mantido. */
            font-size: 8px;
            font-weight: bold;
            display: inline-block;
            /* SKU longo nao quebra a linha em duas */
            white-space: nowrap;
        }

        .produto-nome {
            /* MUL-439: 9px -> 8px e teto de 2 linhas. O nome e o que mais varia de
               tamanho entre produtos, e era ele que fazia a area crescer. */
            font-size: 8px;
            color: #222;
            margin-top: 0.5mm;
            line-height: 1.15;
            max-height: 4.4mm;
            overflow: hidden;
        }

        .etiqueta-marketplace {
            width: 100%;
            text-align: center;
            margin-top: 1mm;
        }

        .etiqueta-marketplace img {
            /* MUL-441: medidas definidas pelo Ruan em 19/08/2026. Largura e altura
               fixas (nao "auto"): a etiqueta ocupa a area inteira reservada a ela,
               em vez de encolher para respeitar a proporcao da imagem. Isso e o que
               deixa o codigo de barras no maior tamanho possivel na impressao. */
            max-width: 98mm;
            max-height: 150mm;
            width: 98mm;
            height: 130mm;
        }

        .nota-fiscal {
            width: 100%;
            text-align: center;
            margin-top: 1mm;
        }

        .nota-fiscal img {
            max-width: 96mm;
            max-height: 128mm;
            width: auto;
            height: auto;
        }

        .nota-titulo {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 1mm;
        }

        .etiqueta-erro {
            border: 1px solid #b00;
            background: #fee;
            color: #800;
            padding: 3mm;
            font-size: 9px;
            text-align: center;
            margin-top: 2mm;
        }

        .item-extra {
            margin-top: 1mm;
            padding-top: 1mm;
            border-top: 1px dotted #999;
        }

        /* NOV-208 — layout 'side': folha unica paisagem */
        .folha-side {
            width: 297mm;
            height: 210mm;
            padding: 4mm 6mm;
            page-break-after: always;
            page-break-inside: avoid;
            overflow: hidden;
        }
        .folha-side:last-child { page-break-after: auto; }

        .side-corpo {
            display: table;
            width: 100%;
            table-layout: fixed;
            margin-top: 2mm;
        }

        .side-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: center;
        }

        .side-col img {
            max-width: 138mm;
            max-height: 168mm;
            width: auto;
            height: auto;
        }

        /* NOV-208 — DANFE Simplificado (modelo etiqueta) renderizado em HTML */
        .danfe-s {
            width: 100%;
            border: 1px solid #000;
            padding: 2mm;
            font-size: 8px;
            text-align: left;
        }
        .danfe-s-titulo {
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 1mm;
        }
        .danfe-s-barcode svg {
            width: 100%;
            height: 12mm;
            display: block;
        }
        .danfe-s-chave {
            text-align: center;
            font-size: 8px;
            margin: 0.5mm 0 1mm;
        }
        .danfe-s-grid { width: 100%; border-collapse: collapse; }
        .danfe-s-grid td { border-top: 1px solid #000; padding: 0.8mm 0; }
        .danfe-s-bloco { border-top: 1px solid #000; padding: 0.8mm 0; }
        .danfe-s-itens {
            width: 100%;
            border-collapse: collapse;
            border-top: 1px solid #000;
        }
        .danfe-s-itens th, .danfe-s-itens td {
            text-align: left;
            padding: 0.5mm 1.5mm 0.5mm 0;
            vertical-align: top;
        }
        .danfe-s-itens th { border-bottom: 1px solid #999; font-size: 7px; }
        .danfe-s-protocolo { border-top: 1px solid #000; padding-top: 0.8mm; }
        .danfe-s-consulta { font-size: 7px; color: #333; margin-top: 0.5mm; }

        .side-col .danfe-s { max-width: 138mm; margin: 0 auto; }

        /* NOV-208 — layout 'footer': nota compacta no rodape (padrao Shopee) */
        .com-rodape .etiqueta-marketplace img { max-height: 80mm; }
        .rodape {
            border-top: 1px dashed #000;
            margin-top: 1mm;
            padding-top: 1mm;
            text-align: center;
        }
        .rodape img {
            max-width: 96mm;
            max-height: 40mm;
            width: auto;
            height: auto;
        }
        .rodape .danfe-s {
            font-size: 6.5px;
            padding: 1mm;
            column-count: 2;
            column-gap: 2.5mm;
        }
        .rodape .danfe-s > * { break-inside: avoid; }
        .rodape .danfe-s-titulo { column-span: all; }
        .rodape .danfe-s-barcode { break-after: avoid; }
        .rodape .danfe-s-titulo { font-size: 8px; margin-bottom: 0.5mm; }
        .rodape .danfe-s-barcode svg { height: 8mm; }
        .rodape .danfe-s-chave { font-size: 6.5px; margin: 0.3mm 0 0.5mm; }
        .rodape .danfe-s-itens th { font-size: 6px; }
        .rodape .danfe-s-consulta { font-size: 6px; }


        /* NOV-208 v2 — etiqueta ML + DACE replicas posicionais (HTML do
           MlLabelPdf, layout identico ao PDF original). Wrappers so
           centralizam e escalam por contexto. Frame nativo: 90 x 148.5mm. */
        .eml-wrap .ml-frame, .dace-wrap .ml-frame { margin: 0 auto; }
        .eml-wrap { zoom: 0.82; }
        .dace-wrap { zoom: 0.90; }
        .com-rodape .eml-wrap { zoom: 0.52; }
        .com-strip .eml-wrap { zoom: 0.68; }
        .rodape .dace-strip { margin: 0 auto; }
        .danfe-strip-barcode svg { width: 88mm; height: 9mm; }
        .eml-wrap .ml-frame img, .dace-wrap .ml-frame img { max-width: none; max-height: none; }
        /* rodape: DACE deitada (90x148.5mm nativa -> 62.5x38mm) pra aproveitar a faixa */
        .rodape .dace-wrap { width: 63mm; height: 38.5mm; margin: 0 auto; overflow: hidden; }
        .rodape .dace-wrap .ml-frame { margin: 0; transform: translateX(62.5mm) rotate(90deg) scale(0.42); transform-origin: 0 0; }
        .side-col .eml-wrap, .side-col .dace-wrap { zoom: 1.1; }

        @media screen {
            body { background: #e5e5e5; padding: 10px; }
            .etiqueta, .folha-side {
                background: #fff;
                margin: 10px auto;
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .etiqueta, .folha-side {
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
@foreach ($etiquetas as $et)
    @php
        $order = $et['order'];
        $items = $et['items'];
        $labelImage = $et['label_image'];
        $labelError = $et['label_error'];
        $invoiceImage = $et['invoice_image'] ?? null;
        $invoiceData = $et['invoice_data'] ?? null;
        $labelData = $et['label_data'] ?? null;
        $daceData = $et['dace_data'] ?? null;
        $daceStrip = $et['dace_strip'] ?? null;
    @endphp

    @if ($layout === 'side')
    {{-- NOV-208: folha unica paisagem — cabecalho no topo, etiqueta + nota lado a lado --}}
    <div class="folha-side">
        <div class="cabecalho">
            {{-- NOV-208: meta alinhada a direita, ao lado do produto (nada acima da imagem) --}}
            <div class="cab-linha">
                <div class="cab-produtos">
                    @foreach ($items as $idx => $item)
                        <div class="produto-row @if ($idx > 0) item-extra @endif">
                            <div class="produto-foto">
                                @if (!empty($item['image_url']))
                                    <img src="{{ $item['image_url'] }}" alt="">
                                @else
                                    <span class="placeholder">sem foto</span>
                                @endif
                            </div>
                            <div class="produto-info">
                                <span class="produto-qtd">{{ $item['quantity'] }}x</span>
                                <span class="produto-sku">{{ $item['parent_sku'] }}</span>
                                <div class="produto-nome">{{ \Illuminate\Support\Str::limit($item['name'], 60) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="cabecalho-meta">
                    <strong>Pedido #{{ $order->order_number }}</strong>
                    @if ($order->marketplace ?: $order->source)
                        &middot; {{ strtoupper($order->marketplace ?: $order->source) }}
                    @endif
                    @if ($order->carrier_name)
                        &middot; <span style="color: #00c; font-weight: bold;">{{ $order->carrier_name }}</span>
                    @endif
                    <br>
                    @php $prazo = ($order->shipping_deadline ?? null) ?: ($order->paid_at ? \Illuminate\Support\Carbon::parse($order->paid_at)->addHours(48) : null); @endphp
                    @if ($prazo)
                        Prazo de envio: <strong>{{ \Illuminate\Support\Carbon::parse($prazo)->format('d/m/Y H:i') }}</strong>
                        <br>
                    @endif
                    Gerado em {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>

        <div class="side-corpo">
            <div class="side-col">
                @if ($labelData)
                @include('labels.partials.etiqueta-ml', ['ml' => $labelData])
            @elseif ($labelImage)
                    <img src="{{ $labelImage }}" alt="Etiqueta {{ $order->order_number }}">
                @else
                    <div class="etiqueta-erro">
                        Etiqueta do marketplace nao disponivel.
                        @if ($labelError)
                            <br>{{ $labelError }}
                        @endif
                    </div>
                @endif
            </div>
            <div class="side-col">
                @if ($daceData)
                @include('labels.partials.dace-resumida', ['dace' => $daceData])
            @elseif ($invoiceData)
                    @include('labels.partials.danfe-simplificada', ['nota' => $invoiceData])
                @elseif ($invoiceImage)
                    <img src="{{ $invoiceImage }}" alt="Nota fiscal {{ $order->order_number }}">
                @else
                    <div class="etiqueta-erro">Nota fiscal nao disponivel para este pedido.</div>
                @endif
            </div>
        </div>
    </div>
    @elseif ($layout === 'footer')
    {{-- NOV-208: folha unica 100x150mm — nota compacta no rodape (padrao Shopee) --}}
    <div class="etiqueta @if (($daceData && !$daceStrip) || $invoiceImage) com-rodape @endif @if ($daceStrip || $invoiceData) com-strip @endif">
        <div class="cabecalho">
            {{-- NOV-208: meta alinhada a direita, ao lado do produto (nada acima da imagem) --}}
            <div class="cab-linha">
                <div class="cab-produtos">
                    @foreach ($items as $idx => $item)
                        <div class="produto-row @if ($idx > 0) item-extra @endif">
                            <div class="produto-foto">
                                @if (!empty($item['image_url']))
                                    <img src="{{ $item['image_url'] }}" alt="">
                                @else
                                    <span class="placeholder">sem foto</span>
                                @endif
                            </div>
                            <div class="produto-info">
                                <span class="produto-qtd">{{ $item['quantity'] }}x</span>
                                <span class="produto-sku">{{ $item['parent_sku'] }}</span>
                                <div class="produto-nome">{{ \Illuminate\Support\Str::limit($item['name'], 60) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="cabecalho-meta">
                    <strong>Pedido #{{ $order->order_number }}</strong>
                    @if ($order->marketplace ?: $order->source)
                        &middot; {{ strtoupper($order->marketplace ?: $order->source) }}
                    @endif
                    @if ($order->carrier_name)
                        &middot; <span style="color: #00c; font-weight: bold;">{{ $order->carrier_name }}</span>
                    @endif
                    <br>
                    @php $prazo = ($order->shipping_deadline ?? null) ?: ($order->paid_at ? \Illuminate\Support\Carbon::parse($order->paid_at)->addHours(48) : null); @endphp
                    @if ($prazo)
                        Prazo de envio: <strong>{{ \Illuminate\Support\Carbon::parse($prazo)->format('d/m/Y H:i') }}</strong>
                        <br>
                    @endif
                    Gerado em {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>

        <div class="etiqueta-marketplace">
            @if ($labelData)
                @include('labels.partials.etiqueta-ml', ['ml' => $labelData])
            @elseif ($labelImage)
                <img src="{{ $labelImage }}" alt="Etiqueta {{ $order->order_number }}">
            @else
                <div class="etiqueta-erro">
                    Etiqueta do marketplace nao disponivel.
                    @if ($labelError)
                        <br>{{ $labelError }}
                    @endif
                </div>
            @endif
        </div>

        @if ($daceData || $invoiceData || $invoiceImage)
        <div class="rodape">
            @if ($daceStrip)
                {!! $daceStrip !!}
            @elseif ($daceData)
                @include('labels.partials.dace-resumida', ['dace' => $daceData])
            @elseif ($invoiceData)
                @include('labels.partials.danfe-strip', ['nota' => $invoiceData])
            @else
                <img src="{{ $invoiceImage }}" alt="Nota fiscal {{ $order->order_number }}">
            @endif
        </div>
        @endif
    </div>
    @else
    <div class="etiqueta">
        <div class="cabecalho">
            {{-- NOV-208: meta alinhada a direita, ao lado do produto (nada acima da imagem) --}}
            <div class="cab-linha">
                <div class="cab-produtos">
                    @foreach ($items as $idx => $item)
                        <div class="produto-row @if ($idx > 0) item-extra @endif">
                            <div class="produto-foto">
                                @if (!empty($item['image_url']))
                                    <img src="{{ $item['image_url'] }}" alt="">
                                @else
                                    <span class="placeholder">sem foto</span>
                                @endif
                            </div>
                            <div class="produto-info">
                                <span class="produto-qtd">{{ $item['quantity'] }}x</span>
                                <span class="produto-sku">{{ $item['parent_sku'] }}</span>
                                <div class="produto-nome">{{ \Illuminate\Support\Str::limit($item['name'], 60) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="cabecalho-meta">
                    <strong>Pedido #{{ $order->order_number }}</strong>
                    @if ($order->marketplace ?: $order->source)
                        &middot; {{ strtoupper($order->marketplace ?: $order->source) }}
                    @endif
                    @if ($order->carrier_name)
                        &middot; <span style="color: #00c; font-weight: bold;">{{ $order->carrier_name }}</span>
                    @endif
                    <br>
                    @php $prazo = ($order->shipping_deadline ?? null) ?: ($order->paid_at ? \Illuminate\Support\Carbon::parse($order->paid_at)->addHours(48) : null); @endphp
                    @if ($prazo)
                        Prazo de envio: <strong>{{ \Illuminate\Support\Carbon::parse($prazo)->format('d/m/Y H:i') }}</strong>
                        <br>
                    @endif
                    Gerado em {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>

        <div class="etiqueta-marketplace">
            @if ($labelData)
                @include('labels.partials.etiqueta-ml', ['ml' => $labelData])
            @elseif ($labelImage)
                <img src="{{ $labelImage }}" alt="Etiqueta {{ $order->order_number }}">
            @else
                <div class="etiqueta-erro">
                    Etiqueta do marketplace nao disponivel.
                    @if ($labelError)
                        <br>{{ $labelError }}
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($daceData || $invoiceData || $invoiceImage)
    {{-- NOV-208: nota (DACE/DANFE) na folha seguinte, com o mesmo cabecalho --}}
    <div class="etiqueta">
        <div class="cabecalho">
            <div class="cabecalho-meta" style="display: block; width: auto; text-align: left; padding-left: 0;">
                <strong>Pedido #{{ $order->order_number }}</strong> &middot; NOTA FISCAL
                @if ($order->marketplace)
                    &middot; {{ strtoupper($order->marketplace) }}
                @endif
            </div>
        </div>
        <div class="nota-fiscal">
            @if ($daceData)
                @include('labels.partials.dace-resumida', ['dace' => $daceData])
            @elseif ($invoiceData)
                @include('labels.partials.danfe-simplificada', ['nota' => $invoiceData])
            @else
                <img src="{{ $invoiceImage }}" alt="Nota fiscal {{ $order->order_number }}">
            @endif
        </div>
    </div>
    @endif
    @endif
@endforeach
</body>
</html>
