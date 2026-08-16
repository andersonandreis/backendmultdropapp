<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu vídeo está pronto - Seller Global</title>
</head>
<body style="margin:0;padding:0;background-color:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e0e0e0;">
    <div style="width:100%;background-color:#0a0a0a;padding:32px 16px;">
        <div style="max-width:560px;margin:0 auto;background-color:#141414;border:1px solid #262626;border-radius:16px;overflow:hidden;">
            <div style="background:linear-gradient(135deg,#141708 0%,#0a0a0a 100%);border-bottom:1px solid rgba(215,255,96,0.18);padding:28px 32px;text-align:center;">
                <div style="font-size:22px;font-weight:800;color:#d7ff60;letter-spacing:-0.02em;">Seller Global</div>
            </div>
            <div style="padding:34px 32px;">
                <div style="font-size:44px;line-height:1;text-align:center;margin-bottom:16px;">🎬</div>
                <h1 style="font-size:23px;font-weight:800;color:#ffffff;margin:0 0 12px;text-align:center;line-height:1.25;">
                    Seu vídeo tá pronto, {{ $user->name ? explode(' ', trim($user->name))[0] : 'campeão' }}!
                </h1>
                <p style="font-size:16px;line-height:1.6;color:#c3c9ba;margin:0 0 22px;text-align:center;">
                    {{ $motiv ?: 'Agora é baixar, postar no seu TikTok e deixar a venda acontecer. Cada vídeo é uma nova chance de vender.' }}
                </p>
                @if($productName)
                <p style="font-size:13px;color:#8a8f80;margin:0 0 22px;text-align:center;">
                    Produto: <strong style="color:#d7ff60;">{{ $productName }}</strong>
                </p>
                @endif
                <div style="text-align:center;margin:28px 0 8px;">
                    <a href="{{ $videoUrl }}" style="display:inline-block;background:#d7ff60;color:#141708;font-weight:800;font-size:16px;padding:15px 34px;border-radius:12px;text-decoration:none;">
                        📥 Baixar meu vídeo
                    </a>
                </div>
                <div style="text-align:center;margin:10px 0 0;">
                    <a href="{{ $appUrl }}" style="display:inline-block;color:#98a08c;font-size:14px;padding:8px;text-decoration:underline;">
                        ver todos os meus vídeos
                    </a>
                </div>
            </div>
            <div style="border-top:1px solid #262626;padding:20px 32px;text-align:center;">
                <p style="font-size:12px;color:#6b7060;margin:0;line-height:1.5;">
                    Você recebeu esse e-mail porque gerou um vídeo na Seller Global.<br>
                    Bons vídeos = boas vendas. 🚀
                </p>
            </div>
        </div>
    </div>
</body>
</html>
