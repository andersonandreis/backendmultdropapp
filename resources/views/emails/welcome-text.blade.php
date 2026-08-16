Ola{{ isset($user->name) && $user->name ? ', '.explode(' ', trim($user->name))[0] : '' }}!

Sua conta no Seller.Global esta pronta.

DADOS DE ACESSO
Login: {{ $user->email }}
Senha: a que voce definiu no cadastro

Entrar no painel: https://seller.global/login?email={{ urlencode($user->email) }}
Esqueci a senha: https://seller.global/reset-password?email={{ urlencode($user->email) }}

Comece por:
- Produtos em alta no TikTok Shop BR
- Criadores IA (afiliados por nicho)
- Gerar Video com IA

Duvidas? Grupo VIP WhatsApp:
https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U

--
Gabriel - Time Seller.Global

Cancelar notificacoes: {{ $emailLog->trackedUrl('https://seller.global/unsubscribe?email='.urlencode($user->email)) }}
