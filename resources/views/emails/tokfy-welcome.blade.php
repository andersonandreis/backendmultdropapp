{{--
    SEL-EMAILTOKFY (12/08) — e-mail de ACESSO LIBERADO da marca TOKFY.

    REGRA DURA: nada de seller.global aqui — nem nome, nem cor (#D7FF60), nem
    link, nem assinatura. Tambem nao fala de TikTok Shop, Kalodata,
    dropshipping nem fornecedores: quem compra Tokfy comprou GERACAO DE VIDEO
    COM IA, nao marketplace.

    Cores/nome/dominio/atendente vem de App\Support\BrandKit (espelho de
    src/config/brand.ts) via $brand — nao escrever cor na mao aqui.
    Estilo e todo INLINE de proposito: Gmail/Outlook descartam <style> em <head>.
--}}
@php
    $accent   = $brand['accent_hex']   ?? '#FF0050';
    $accent2  = $brand['accent_hex2']  ?? '#00F2EA';
    $gradient = $brand['cta_gradient'] ?? ('linear-gradient(90deg, ' . $accent . ', ' . $accent2 . ')');
    $marca    = $brand['name']         ?? 'Tokfy';
    $login    = ($brand['login_url'] ?? 'https://tokfy.io/login') . '?email=' . urlencode($user->email);
    $reset    = ($brand['reset_url'] ?? 'https://tokfy.io/reset-password') . '?email=' . urlencode($user->email);
    $site     = $brand['site_url']      ?? 'https://tokfy.io';
    $suporte  = $brand['support_email'] ?? 'suporte@tokfy.io';
    $atende   = $brand['support_agent_name'] ?? 'Nina';
    $primeiro = ($user->name ?? '') ? explode(' ', trim($user->name))[0] : '';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu acesso à {{ $marca }} está liberado</title>
</head>
<body style="margin:0;padding:0;background-color:#08080c;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#ffffff;">

{{-- preheader: primeira linha que aparece na caixa de entrada, invisivel no corpo --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;height:0;width:0;">
    Plano {{ $plan->name }} ativado. Seus dados de acesso estão aqui dentro.
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#08080c" style="background-color:#08080c;">
<tr><td align="center" style="padding:32px 16px;">

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background-color:#0f0f16;border-radius:16px;overflow:hidden;border:1px solid rgba(255,0,80,0.25);">

        {{-- faixa de topo em gradiente rosa->ciano (identidade Tokfy) --}}
        <tr><td bgcolor="{{ $accent }}" style="background:{{ $accent }};background:{{ $gradient }};height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>

        {{-- cabecalho / wordmark --}}
        <tr><td align="center" style="padding:32px 32px 20px 32px;background-color:#0f0f16;">
            <div style="font-size:26px;font-weight:900;letter-spacing:-0.5px;line-height:1;">
                <span style="color:{{ $accent }};">tokfy</span><span style="color:{{ $accent2 }};">.io</span>
            </div>
            <div style="margin-top:14px;">
                <span style="display:inline-block;background-color:{{ $accent }};color:#ffffff;font-size:10.5px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;padding:5px 14px;border-radius:20px;">Acesso liberado</span>
            </div>
            <p style="margin:14px 0 0 0;font-size:12px;color:rgba(255,255,255,0.6);font-weight:600;letter-spacing:0.3px;">Vídeos e avatares com Inteligência Artificial</p>
        </td></tr>

        {{-- saudacao --}}
        <tr><td style="padding:8px 32px 0 32px;">
            <h1 style="margin:0 0 10px 0;font-size:21px;font-weight:800;color:#ffffff;line-height:1.35;">Olá{{ $primeiro ? ', '.$primeiro : '' }}! Sua conta está pronta</h1>
            <p style="margin:0 0 22px 0;font-size:14px;color:rgba(255,255,255,0.72);line-height:1.65;">Seu pagamento foi confirmado e o acesso à {{ $marca }} já está liberado. Abaixo estão os dados para entrar.</p>
        </td></tr>

        {{-- plano contratado --}}
        <tr><td style="padding:0 32px 18px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#16161f;border:1px solid rgba(255,255,255,0.08);border-radius:12px;">
                <tr><td style="padding:16px 20px;">
                    <p style="margin:0 0 4px 0;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,0.45);">Plano contratado</p>
                    <p style="margin:0;font-size:17px;font-weight:800;color:{{ $accent2 }};">{{ $plan->name }}</p>
                </td></tr>
            </table>
        </td></tr>

        {{-- dados de acesso --}}
        <tr><td style="padding:0 32px 22px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#1a0d16;border:1px solid rgba(255,0,80,0.35);border-radius:12px;">
                <tr><td style="padding:20px;">
                    <p style="margin:0 0 14px 0;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:{{ $accent }};">Seus dados de acesso</p>

                    <p style="margin:0 0 6px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:rgba(255,255,255,0.5);">E-mail</p>
                    <p style="margin:0 0 16px 0;font-size:15px;font-weight:600;color:#ffffff;word-break:break-all;">{{ $user->email }}</p>

                    <p style="margin:0 0 6px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:rgba(255,255,255,0.5);">Senha</p>
                    @if($initialPassword)
                        <p style="margin:0 0 10px 0;font-size:15px;font-weight:700;color:#ffffff;font-family:'Courier New',Courier,monospace;background-color:#25101d;display:inline-block;padding:4px 10px;border-radius:6px;">{{ $initialPassword }}</p>
                        <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);line-height:1.55;">Troque a senha depois do primeiro acesso, no menu da sua conta.</p>
                    @else
                        <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.75);line-height:1.55;">A que você definiu no cadastro. Se não lembrar, use o link de redefinir logo abaixo.</p>
                    @endif
                </td></tr>
            </table>
        </td></tr>

        {{-- CTA --}}
        <tr><td align="center" style="padding:0 32px 10px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                <tr><td align="center" bgcolor="{{ $accent }}" style="background:{{ $accent }};background:{{ $gradient }};border-radius:10px;">
                    <a href="{{ $login }}" style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:800;color:#ffffff;text-decoration:none;letter-spacing:0.3px;">Entrar na minha conta</a>
                </td></tr>
            </table>
        </td></tr>

        <tr><td align="center" style="padding:6px 32px 22px 32px;">
            <a href="{{ $reset }}" style="color:{{ $accent2 }};font-size:12.5px;font-weight:600;text-decoration:underline;">Esqueci a senha — redefinir</a>
        </td></tr>

        {{-- primeiros passos (so as features que a marca entrega) --}}
        <tr><td style="padding:0 32px 8px 32px;">
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:0 0 20px 0;">
            <p style="margin:0 0 14px 0;font-size:13.5px;font-weight:800;color:#ffffff;">Comece por aqui:</p>
            <p style="margin:0 0 10px 0;font-size:13px;color:rgba(255,255,255,0.72);line-height:1.6;">
                <span style="color:{{ $accent }};font-weight:800;">Novo Vídeo</span> — descreva o que você quer e a IA gera o vídeo pronto para publicar.
            </p>
            <p style="margin:0 0 10px 0;font-size:13px;color:rgba(255,255,255,0.72);line-height:1.6;">
                <span style="color:{{ $accent }};font-weight:800;">Meu Avatar / Marca</span> — crie um apresentador próprio e use ele em todos os vídeos.
            </p>
            <p style="margin:0 0 20px 0;font-size:13px;color:rgba(255,255,255,0.72);line-height:1.6;">
                <span style="color:{{ $accent }};font-weight:800;">Meus Vídeos</span> — sua galeria: baixe, refaça ou gere variações do que já criou.
            </p>
        </td></tr>

        {{-- suporte --}}
        <tr><td style="padding:0 32px 24px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#16161f;border:1px solid rgba(0,242,234,0.22);border-radius:12px;">
                <tr><td align="center" style="padding:16px 20px;">
                    <p style="margin:0 0 6px 0;font-size:12.5px;color:rgba(255,255,255,0.75);line-height:1.6;">Ficou com dúvida? É só responder este e-mail.</p>
                    <a href="mailto:{{ $suporte }}" style="color:{{ $accent2 }};font-size:13px;font-weight:700;text-decoration:none;">{{ $suporte }}</a>
                </td></tr>
            </table>
        </td></tr>

        {{-- rodape --}}
        <tr><td align="center" style="padding:20px 32px 26px 32px;background-color:#0a0a10;border-top:1px solid rgba(255,255,255,0.07);">
            <p style="margin:0 0 4px 0;font-size:12.5px;font-weight:800;color:{{ $accent }};">{{ $atende }}</p>
            <p style="margin:0 0 14px 0;font-size:11px;color:rgba(255,255,255,0.5);">Time {{ $marca }}</p>
            <p style="margin:0;font-size:10.5px;color:rgba(255,255,255,0.35);line-height:1.7;">
                Você recebeu este e-mail porque contratou um plano na <a href="{{ $site }}" style="color:rgba(255,255,255,0.5);text-decoration:underline;">{{ $brand['domain'] ?? 'tokfy.io' }}</a>.<br>
                &copy; {{ date('Y') }} {{ $brand['copyright_name'] ?? 'Tokfy' }}. Todos os direitos reservados.
            </p>
        </td></tr>

    </table>

</td></tr>
</table>

</body>
</html>
