<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packing Slip - Pedido #{{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #111;
            background: #fff;
            padding: 16px;
        }

        /* === CABECALHO === */
        .header {
            border-bottom: 3px solid #1a1a2e;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }

        .header-inner {
            width: 100%;
        }

        .header-inner td {
            vertical-align: middle;
        }

        .logo-cell {
            width: 160px;
        }

        .logo-text {
            font-size: 22px;
            font-weight: bold;
            color: #1a1a2e;
            letter-spacing: -0.5px;
        }

        .logo-sub {
            font-size: 9px;
            color: #555;
            margin-top: 2px;
        }

        .order-info-cell {
            text-align: right;
        }

        .order-number {
            font-size: 18px;
            font-weight: bold;
            color: #1a1a2e;
        }

        .order-meta {
            font-size: 9px;
            color: #444;
            margin-top: 3px;
            line-height: 1.5;
        }

        .badge {
            display: inline-block;
            background: #1a1a2e;
            color: #fff;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }

        /* === TITULO SECAO === */
        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }

        /* === TABELA DE ITENS === */
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            background: #f0f0f0;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 5px 8px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
            vertical-align: middle;
        }

        .item-row:nth-child(even) {
            background: #fafafa;
        }

        /* Celula de imagem */
        .img-cell {
            width: 72px;
            text-align: center;
        }

        .product-img {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border: 1px solid #eee;
            border-radius: 4px;
            background: #f8f8f8;
        }

        .no-img {
            width: 64px;
            height: 64px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            line-height: 64px;
            font-size: 24px;
            color: #bbb;
        }

        /* Celula de SKU */
        .sku-cell {
            width: 130px;
        }

        .sku-value {
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            font-weight: bold;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 3px 6px;
            display: inline-block;
            word-break: break-all;
        }

        /* Celula de produto */
        .product-name {
            font-size: 11px;
            font-weight: bold;
            line-height: 1.4;
            margin-bottom: 3px;
        }

        .variation-tag {
            font-size: 9px;
            color: #666;
            background: #f0f0f0;
            padding: 1px 5px;
            border-radius: 2px;
            display: inline-block;
            margin-top: 3px;
        }

        /* Celula de quantidade */
        .qty-cell {
            width: 55px;
            text-align: center;
        }

        .qty-badge {
            display: inline-block;
            background: #1a1a2e;
            color: #fff;
            font-size: 14px;
            font-weight: bold;
            width: 36px;
            height: 36px;
            line-height: 36px;
            border-radius: 50%;
            text-align: center;
        }

        /* Celula de conferencia */
        .check-cell {
            width: 55px;
            text-align: center;
        }

        .check-box {
            width: 26px;
            height: 26px;
            border: 2px solid #555;
            border-radius: 3px;
            display: inline-block;
        }

        /* === FOOTER === */
        .footer {
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            font-size: 8px;
            color: #888;
        }

        .footer-inner {
            width: 100%;
        }

        .footer-inner td {
            vertical-align: bottom;
        }

        .footer-left {
            font-size: 8px;
            color: #888;
        }

        .footer-right {
            text-align: right;
        }

        .hubai-brand {
            font-size: 10px;
            font-weight: bold;
            color: #1a1a2e;
        }
    </style>
</head>
<body>

    {{-- CABECALHO --}}
    <div class="header">
        <table class="header-inner">
            <tr>
                <td class="logo-cell">
                    <div class="logo-text">HubAI</div>
                    <div class="logo-sub">Packing Slip — Conferencia Interna</div>
                </td>
                <td class="order-info-cell">
                    <div class="order-number">Pedido #{{ $order->order_number }}</div>
                    <div class="order-meta">
                        @if($order->source)
                            <span class="badge">{{ strtoupper($order->source) }}</span>
                        @endif
                        @if($order->channel_name)
                            <span class="badge">{{ $order->channel_name }}</span>
                        @endif
                        <br>
                        @if($order->customer_name)
                            Cliente: {{ $order->customer_name }}<br>
                        @endif
                        @if($order->status)
                            Status: {{ $order->status }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ITENS --}}
    <div class="section-title">Itens do Pedido ({{ count($items) }} {{ count($items) === 1 ? 'item' : 'itens' }})</div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="img-cell">Foto</th>
                <th class="sku-cell">SKU</th>
                <th>Produto</th>
                <th class="qty-cell">Qtd</th>
                <th class="check-cell">OK</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr class="item-row">
                {{-- IMAGEM --}}
                <td class="img-cell">
                    @if(!empty($item['image_url']))
                        <img src="{{ $item['image_url'] }}" class="product-img" alt="{{ $item['sku'] }}">
                    @else
                        <div class="no-img">?</div>
                    @endif
                </td>

                {{-- SKU --}}
                <td class="sku-cell">
                    <span class="sku-value">{{ $item['sku'] }}</span>
                </td>

                {{-- NOME + VARIACAO --}}
                <td>
                    <div class="product-name">{{ $item['name'] }}</div>
                    @if(!empty($item['variation']))
                        <span class="variation-tag">{{ $item['variation'] }}</span>
                    @endif
                </td>

                {{-- QUANTIDADE --}}
                <td class="qty-cell">
                    <span class="qty-badge">{{ $item['quantity'] }}</span>
                </td>

                {{-- CHECKBOX CONFERENCIA --}}
                <td class="check-cell">
                    <span class="check-box"></span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align:center; padding:20px; color:#888;">
                    Nenhum item encontrado neste pedido.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    {{-- FOOTER --}}
    <div class="footer">
        <table class="footer-inner">
            <tr>
                <td class="footer-left">
                    Documento gerado em: {{ $generatedAt }} &nbsp;|&nbsp;
                    Uso exclusivamente interno &nbsp;|&nbsp;
                    Nao e etiqueta de envio
                </td>
                <td class="footer-right">
                    <span class="hubai-brand">api.hubai.io</span>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
