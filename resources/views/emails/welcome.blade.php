<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Seller.Global</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#ffffff;">
<div style="width:100%;background:#0a0a0f;padding:32px 16px;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background:linear-gradient(180deg,#1a0a1f 0%,#0f0a15 100%);border-radius:16px;overflow:hidden;border:1px solid rgba(215,255,96,0.2);">
    <tr><td style="padding:28px 32px 20px 32px;background:linear-gradient(135deg,#FF0050 0%,#d7ff60 200%);text-align:center;">
        <div style="display:inline-block;background:rgba(0,0,0,0.85);padding:12px 24px;border-radius:12px;">
            <span style="font-size:22px;font-weight:900;color:#d7ff60;letter-spacing:0.5px;">SELLER.GLOBAL</span>
        </div>
        <p style="margin:12px 0 0 0;font-size:12px;color:rgba(255,255,255,0.9);font-weight:600;text-transform:uppercase;letter-spacing:1px;">Gere videos que vendem no TikTok Shop com IA</p>
    </td></tr>
    <tr><td style="padding:32px 32px 8px 32px;">
        <h1 style="margin:0 0 12px 0;font-size:22px;font-weight:800;color:#ffffff;line-height:1.3;">Ola{{ isset($user->name) && $user->name ? ', '.explode(' ', trim($user->name))[0] : '' }}! Sua conta esta pronta</h1>
        <p style="margin:0 0 20px 0;font-size:14px;color:rgba(255,255,255,0.75);line-height:1.6;">Voce ja pode acessar o painel do Seller.Global. Abaixo estao seus dados de acesso.</p>
    </td></tr>
    <tr><td style="padding:0 32px;">
        <div style="background:rgba(215,255,96,0.08);border:1px solid rgba(215,255,96,0.25);border-radius:12px;padding:20px;">
            <p style="margin:0 0 8px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#d7ff60;">Seu login</p>
            <p style="margin:0 0 18px 0;font-size:16px;font-weight:600;color:#ffffff;word-break:break-all;">{{ $user->email }}</p>
            <p style="margin:0 0 8px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#d7ff60;">Senha</p>
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.85);line-height:1.5;">A que voce definiu no cadastro. Se esqueceu, use o botao abaixo para redefinir.</p>
        </div>
    </td></tr>
    <tr><td style="padding:24px 32px 8px 32px;text-align:center;">
        <a href="https://seller.global/login?email={{ urlencode($user->email) }}" style="display:inline-block;background:#d7ff60;color:#0f1a08;font-size:15px;font-weight:800;text-decoration:none;padding:14px 32px;border-radius:10px;letter-spacing:0.3px;">Entrar no meu painel</a>
    </td></tr>
    <tr><td style="padding:0 32px 24px 32px;text-align:center;">
        <a href="https://seller.global/reset-password?email={{ urlencode($user->email) }}" style="display:inline-block;color:rgba(215,255,96,0.9);font-size:12.5px;font-weight:600;text-decoration:underline;padding:8px;">Esqueci a senha - redefinir</a>
    </td></tr>
    <tr><td style="padding:8px 32px 16px 32px;">
        <p style="margin:0 0 12px 0;font-size:13px;font-weight:700;color:#ffffff;">Comece por aqui:</p>
        <p style="margin:0 0 8px 0;font-size:13px;color:rgba(255,255,255,0.75);line-height:1.6;">&#9679; <strong style="color:#d7ff60;">Produtos em alta:</strong> descubra o que esta vendendo agora no TikTok Shop BR</p>
        <p style="margin:0 0 8px 0;font-size:13px;color:rgba(255,255,255,0.75);line-height:1.6;">&#9679; <strong style="color:#d7ff60;">Criadores IA:</strong> encontre parceiros afiliados por nicho</p>
        <p style="margin:0 0 16px 0;font-size:13px;color:rgba(255,255,255,0.75);line-height:1.6;">&#9679; <strong style="color:#d7ff60;">Gerar Video:</strong> crie criativos com IA em minutos</p>
    </td></tr>
    <tr><td style="padding:16px 32px 24px 32px;text-align:center;background:rgba(37,211,102,0.08);border-top:1px solid rgba(37,211,102,0.2);">
        <p style="margin:0 0 8px 0;font-size:12.5px;color:rgba(255,255,255,0.8);">Duvidas? Fale comigo direto no WhatsApp:</p>
        <a href="https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U" style="display:inline-block;color:#25D366;font-size:13.5px;font-weight:700;text-decoration:none;">&#128172; Entrar no canal oficial</a>
    </td></tr>
    <tr><td style="padding:20px 32px;text-align:center;background:#050508;border-top:1px solid rgba(255,255,255,0.08);">
        <p style="margin:0 0 6px 0;font-size:12px;font-weight:700;color:#d7ff60;">Gabriel</p>
        <p style="margin:0 0 12px 0;font-size:11px;color:rgba(255,255,255,0.55);">Time Seller.Global</p>
        <p style="margin:0;font-size:10px;color:rgba(255,255,255,0.35);line-height:1.6;">
            <a href="{{ $emailLog->trackedUrl('https://seller.global/unsubscribe?email='.urlencode($user->email)) }}" style="color:rgba(255,255,255,0.5);text-decoration:underline;">Cancelar notificacoes</a>
            &nbsp;&middot;&nbsp;
            <a href="https://seller.global/privacy" style="color:rgba(255,255,255,0.5);text-decoration:underline;">Politica de privacidade</a>
        </p>
    </td></tr>
    </table>
</div>
</body>
</html>
