@extends('emails.layout')

@section('title', 'Ação necessária: reconecte sua integração')

@section('content')
    <h1 style="font-size:22px;font-weight:700;color:#1a1a2e;margin-bottom:8px;line-height:1.3;">
        Ola, {{ $userName }}!
    </h1>
    <p style="font-size:15px;color:#555e75;line-height:1.7;margin-bottom:24px;">
        Sua integração com
        <strong>{{ implode(' e ', $platforms) }}</strong>
        foi desconectada — isso acontece quando o token de acesso expira.
        <br /><br />
        Para que seus pedidos continuem sendo sincronizados normalmente, você precisa reconectar agora.
        É rápido: menos de 1 minuto.
    </p>

    <!-- Aviso destaque -->
    <div style="
        background:#fff7ed;
        border-left:4px solid #f97316;
        border-radius:6px;
        padding:16px 20px;
        margin-bottom:28px;
    ">
        <p style="font-size:14px;color:#92400e;font-weight:600;margin-bottom:4px;">
            Enquanto desconectado, novos pedidos NAO sao capturados.
        </p>
        <p style="font-size:13px;color:#92400e;">
            Reconecte agora para retomar a sincronizacao automatica.
        </p>
    </div>

    <!-- Passos -->
    <p style="font-size:14px;font-weight:700;color:#1a1a2e;margin-bottom:12px;">
        Como reconectar:
    </p>
    <ol style="font-size:14px;color:#555e75;line-height:2;margin:0 0 28px 20px;">
        <li>Acesse seu painel MultDrop</li>
        <li>Vá em <strong>Integrações</strong></li>
        <li>Clique em <strong>Conectar</strong> ao lado de {{ implode(' / ', $platforms) }}</li>
        <li>Autorize o acesso na página da plataforma</li>
    </ol>

    <!-- CTA -->
    <div style="text-align:center;margin-bottom:32px;">
        <a href="https://fornecefy.io/app/marketplace-accounts"
           style="
            display:inline-block;
            background:linear-gradient(135deg, #6c63ff 0%, #3f3d8f 100%);
            color:#ffffff;
            font-size:15px;
            font-weight:700;
            text-decoration:none;
            padding:14px 36px;
            border-radius:8px;
            letter-spacing:0.3px;
        ">
            Reconectar Agora &rarr;
        </a>
    </div>

    <p style="font-size:13px;color:#8a8fa8;line-height:1.7;text-align:center;">
        Duvidas? Fale com nosso suporte.<br />
        Equipe MultDrop / HubAI
    </p>
@endsection

@section('footer')
    <p style="font-size:13px;color:#555e75;font-weight:600;margin-bottom:4px;">
        Equipe MultDrop
    </p>
@endsection
