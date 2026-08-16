<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plano {{ $plan->name }} ativado - Seller Global</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #0a0a0a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #e0e0e0;
        }
        .wrapper {
            max-width: 560px;
            margin: 40px auto;
            background-color: #111111;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #222;
        }
        .header {
            background-color: #111111;
            padding: 36px 40px 24px;
            text-align: center;
            border-bottom: 1px solid #1e1e1e;
        }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: #d7ff60;
            letter-spacing: -0.5px;
        }
        .badge {
            display: inline-block;
            margin-top: 12px;
            background-color: #d7ff60;
            color: #0a0a0a;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .body {
            padding: 32px 40px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
        }
        .subtitle {
            font-size: 14px;
            color: #888;
            margin: 0 0 28px;
        }
        .plan-box {
            background-color: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .plan-box .plan-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin-bottom: 4px;
        }
        .plan-box .plan-name {
            font-size: 18px;
            font-weight: 700;
            color: #d7ff60;
        }
        .credentials-box {
            background-color: #0f1a00;
            border: 1px solid #d7ff60;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .credentials-box .cred-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #d7ff60;
            margin-bottom: 14px;
        }
        .cred-row {
            font-size: 14px;
            color: #e0e0e0;
            margin-bottom: 8px;
            line-height: 1.5;
        }
        .cred-row strong {
            color: #ffffff;
        }
        .cred-row .value {
            font-family: "Courier New", Courier, monospace;
            background-color: #1a2a00;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        .existing-user-box {
            background-color: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #aaa;
        }
        .cta-btn {
            display: block;
            text-align: center;
            background-color: #d7ff60;
            color: #0a0a0a;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            padding: 14px 24px;
            border-radius: 8px;
            margin-bottom: 28px;
        }
        .divider {
            border: none;
            border-top: 1px solid #1e1e1e;
            margin: 0 0 24px;
        }
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0 0 28px;
        }
        .info-list li {
            font-size: 13px;
            color: #888;
            padding: 6px 0;
            border-bottom: 1px solid #1a1a1a;
        }
        .info-list li:last-child { border-bottom: none; }
        .info-list li span { color: #e0e0e0; }
        .footer {
            padding: 20px 40px 28px;
            text-align: center;
            font-size: 11px;
            color: #555;
        }
        .footer a { color: #888; text-decoration: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <div class="logo-text">Seller Global</div>
            <div class="badge">Plano ativado</div>
        </div>

        <div class="body">
            <h1>Olá, {{ $user->name }}!</h1>
            <p class="subtitle">Seu plano foi ativado com sucesso. Aqui estão os detalhes do seu acesso.</p>

            <div class="plan-box">
                <div class="plan-label">Plano contratado</div>
                <div class="plan-name">{{ $plan->name }}</div>
            </div>

            @if($initialPassword)
            <div class="credentials-box">
                <div class="cred-title">Seus dados de acesso</div>
                <div class="cred-row"><strong>E-mail:</strong> <span class="value">{{ $user->email }}</span></div>
                <div class="cred-row"><strong>Senha inicial:</strong> <span class="value">{{ $initialPassword }}</span></div>
                <div class="cred-row" style="margin-top:12px; font-size:12px; color:#888;">
                    Recomendamos alterar sua senha após o primeiro acesso.
                </div>
            </div>
            @else
            <div class="existing-user-box">
                Seu plano foi ativado na sua conta existente. Faça login com o e-mail cadastrado.
            </div>
            @endif

            <a href="https://seller.global/login" class="cta-btn">Acessar minha conta</a>

            <hr class="divider">

            <ul class="info-list">
                <li>Dúvidas? <span>Entre em contato via chat no painel</span></li>
                <li>Acesso: <span>seller.global/login</span></li>
            </ul>
        </div>

        <div class="footer">
            Você recebeu este e-mail porque adquiriu um plano no <a href="https://seller.global">Seller Global</a>.<br>
            &copy; {{ date('Y') }} Seller Global. Todos os direitos reservados.
        </div>
    </div>

    {{-- SEL-113-blade: bloco CTA grupo WhatsApp quando auto-invite ativa --}}
    @if(!empty($whatsappGroupUrl))
    <div style="margin: 20px 0; padding: 16px; background: #dcfce7; border: 2px solid #25D366; border-radius: 8px; text-align: center;">
      <div style="font-size: 15px; font-weight: bold; color: #075E54; margin-bottom: 6px;">🎉 Você entrou no grupo VIP dos primeiros clientes</div>
      <div style="font-size: 13px; color: #333; margin-bottom: 12px;">Conteúdo exclusivo, networking e suporte prioritário no WhatsApp.</div>
      <a href="{{ $whatsappGroupUrl }}" style="display: inline-block; padding: 12px 20px; background: #25D366; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 13px;">Entrar no grupo WhatsApp</a>
    </div>
    @endif

</body>
</html>
