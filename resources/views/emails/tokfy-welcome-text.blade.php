@php
    $marca    = $brand['name'] ?? 'Tokfy';
    $login    = ($brand['login_url'] ?? 'https://tokfy.io/login') . '?email=' . urlencode($user->email);
    $reset    = ($brand['reset_url'] ?? 'https://tokfy.io/reset-password') . '?email=' . urlencode($user->email);
    $suporte  = $brand['support_email'] ?? 'suporte@tokfy.io';
    $atende   = $brand['support_agent_name'] ?? 'Nina';
    $primeiro = ($user->name ?? '') ? explode(' ', trim($user->name))[0] : '';
@endphp
Ola{{ $primeiro ? ', '.$primeiro : '' }}! Sua conta esta pronta.

Seu pagamento foi confirmado e o acesso a {{ $marca }} ja esta liberado.

PLANO CONTRATADO
{{ $plan->name }}

DADOS DE ACESSO
E-mail: {{ $user->email }}
@if($initialPassword)
Senha: {{ $initialPassword }}
(troque a senha depois do primeiro acesso, no menu da sua conta)
@else
Senha: a que voce definiu no cadastro
@endif

Entrar na minha conta:
{{ $login }}

Esqueci a senha - redefinir:
{{ $reset }}

COMECE POR AQUI
- Novo Video: descreva o que voce quer e a IA gera o video pronto para publicar.
- Meu Avatar / Marca: crie um apresentador proprio e use ele em todos os videos.
- Meus Videos: sua galeria - baixe, refaca ou gere variacoes do que ja criou.

Ficou com duvida? E so responder este e-mail: {{ $suporte }}

--
{{ $atende }}
Time {{ $marca }}
{{ $brand['site_url'] ?? 'https://tokfy.io' }}

Voce recebeu este e-mail porque contratou um plano na {{ $brand['domain'] ?? 'tokfy.io' }}.
(c) {{ date('Y') }} {{ $brand['copyright_name'] ?? 'Tokfy' }}. Todos os direitos reservados.
