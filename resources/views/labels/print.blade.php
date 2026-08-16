<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Imprimir Etiqueta - Pedido #{{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            padding: 20px;
            background-color: #f3f4f6;
        }

        .printer-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #ccc;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .header-left {
            width: 60%;
        }

        .header-right {
            width: 40%;
            text-align: right;
            font-size: 10px;
        }

        .products {
            font-size: 11px;
            margin-top: 10px;
        }

        .product-item {
            margin-bottom: 5px;
            font-weight: bold;
        }

        .label-image-container {
            text-align: center;
            margin-top: 20px;
        }

        .label-image {
            max-width: 100%;
            height: auto;
            max-height: 800px;
            /* A4 height approx constraint */
        }

        .product-thumb {
            width: 40px;
            height: 40px;
            object-fit: contain;
            vertical-align: middle;
            margin-right: 5px;
        }

        .print-btn {
            display: block;
            width: 200px;
            margin: 20px auto;
            padding: 10px;
            background: #000;
            color: #fff;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            margin-bottom: 20px;
        }

        /* Print Specific Styles */
        @media print {
            body {
                padding: 0;
                background-color: #fff;
            }

            .printer-container {
                border: none;
                padding: 0;
                max-width: none;
                width: 100%;
            }

            .print-btn {
                display: none;
            }

            @page {
                size: portrait;
                /* Defines the standard A4 or Zebra layout */
                margin: 0;
            }
        }
    </style>
</head>

<body>

    <button onclick="window.print()" class="print-btn">🖨️ IMPRIMIR ETIQUETA</button>

    <div class="printer-container">

        @if(!$labelUrl)
            <div class="alert-error">
                <strong>Erro:</strong> {{ $status['reason'] ?? 'A etiqueta ainda não está disponível.' }}
            </div>
        @else
            <div class="header">
                <div class="header-left">
                    <p style="font-size: 10px;">Prazo de envio:
                        <strong>{{ \Carbon\Carbon::parse($order->paid_at)->addHours(48)->format('d/m/Y H:i') }}</strong></p>
                    @if($order->carrier_name)
                        <p style="font-size: 11px; color: blue; font-weight: bold; margin-top: 5px;">Transportadora:
                            {{ $order->carrier_name }}</p>
                    @endif

                    <div class="products">
                        @foreach($order->items as $item)
                            <div class="product-item">
                                @if($item->product && $item->product->media->count() > 0)
                                    <img src="{{ asset('storage/' . $item->product->media->first()->file_path) }}"
                                        class="product-thumb">
                                @else
                                    <span style="display:inline-block; width:40px; text-align:center;">📦</span>
                                @endif
                                {{ $item->quantity }}x ({{ $item->sku }}) {{ Str::limit($item->product_name, 35) }}
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="header-right">
                    <strong>{{ $order->order_number }}</strong>
                    <br>
                    Origem: {{ $order->source }}
                    <br>
                    Gerado em: {{ now()->format('d/m/Y H:i:s') }}
                </div>
            </div>

            <div class="label-image-container">
                <!-- If the URL is actually a PDF, we might need an iframe or object tag,
                         But Meli/Shopee typically provide printable ZPL/PDF or PNG.
                         For HTML label view approach, if it's an image: -->
                @if(Str::endsWith($labelUrl, ['.png', '.jpg', '.jpeg']))
                    <img src="{{ $labelUrl }}" class="label-image" alt="Etiqueta de Envio">
                @else
                    <iframe src="{{ $labelUrl }}" width="100%" height="700px" style="border:none;"></iframe>
                @endif
            </div>
        @endif
    </div>

    @if($labelUrl)
        <script>
            // Auto trigger print when loaded (optional, helpful for fast workflows)
            window.onload = function () {
                setTimeout(() => {
                    window.print();
                }, 1000);
            }
        </script>
    @endif
</body>

</html>