<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopee Conectada</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #ee4d2d 0%, #ff7337 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 48px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        .icon {
            width: 72px;
            height: 72px;
            background: #f0fdf4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon svg { width: 36px; height: 36px; }
        h1 {
            font-size: 1.4rem;
            color: #111827;
            margin-bottom: 10px;
            font-weight: 700;
        }
        p {
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 28px;
        }
        .btn {
            display: inline-block;
            background: #ee4d2d;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn:hover { background: #d93f21; }
        .shop-id {
            margin-top: 20px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5"/>
            </svg>
        </div>
        <h1>Shopee conectada!</h1>
        <p>Sua loja Shopee foi vinculada com sucesso. Agora você pode importar produtos e sincronizar pedidos automaticamente.</p>
        @if($returnUrl)
            <a href="{{ $returnUrl }}" class="btn">Voltar ao Painel</a>
        @endif
        @if($shopId)
            <div class="shop-id">Shop ID: {{ $shopId }}</div>
        @endif
    </div>
    @if($returnUrl)
    <script>
        // Auto-redirect após 4 segundos
        setTimeout(function() {
            window.location.href = "{{ $returnUrl }}";
        }, 4000);
    </script>
    @endif
</body>
</html>
