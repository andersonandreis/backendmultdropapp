@php
    $user = auth()->user();
    $role = $user?->role;
    $isApp = request()->is('app*');
    $expected = $isApp ? 'client' : 'super_admin / supplier';
    $correctPanel = match ($role) {
        'super_admin', 'supplier' => '/admin',
        'client'                  => '/app',
        default                   => null,
    };
    $suggestPanel = $correctPanel && (
        ($isApp && $correctPanel !== '/app') ||
        (!$isApp && $correctPanel !== '/admin')
    );
    $logoutTarget = $isApp ? '/app/login' : '/admin/login';
    $logoutUrl = '/logout-all?to=' . urlencode($logoutTarget);
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Acesso negado | HubAI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --hub-emerald: 16,185,129; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            background: radial-gradient(ellipse at 50% 20%, rgba(var(--hub-emerald),0.05) 0%, #010409 70%);
            color: #f8fafc;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            max-width: 440px; width: 100%;
            background: rgba(8,18,22,0.95);
            border: 1px solid rgba(var(--hub-emerald),0.1);
            border-radius: 20px;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 8px 40px rgba(0,0,0,0.5), 0 0 0 1px rgba(var(--hub-emerald),0.05);
            animation: card-in 0.4s ease both;
        }
        @keyframes card-in {
            from { opacity:0; transform: translateY(16px) scale(0.98); }
            to   { opacity:1; transform: translateY(0) scale(1); }
        }

        .icon {
            width: 64px; height: 64px;
            margin: 0 auto 1.25rem;
            border-radius: 50%;
            background: rgba(var(--hub-emerald),0.1);
            display: flex; align-items: center; justify-content: center;
        }
        .icon svg { width: 28px; height: 28px; color: rgb(var(--hub-emerald)); }

        h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.5rem; }
        .desc { font-size: 0.9rem; color: rgba(248,250,252,0.55); line-height: 1.5; margin-bottom: 1.5rem; }

        .user-info {
            background: rgba(var(--hub-emerald),0.04);
            border: 1px solid rgba(var(--hub-emerald),0.08);
            border-radius: 12px;
            padding: 1rem;
            text-align: left;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        .user-info .label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(248,250,252,0.4); font-weight: 600; margin-bottom: 0.35rem; }
        .user-info .name { font-weight: 600; }
        .user-info .email { color: rgba(248,250,252,0.6); }
        .user-info .meta { margin-top: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 6px;
            font-size: 0.75rem; font-weight: 600;
        }
        .pill-warn { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.25); }
        .pill-info { background: rgba(var(--hub-cyan, 6,182,212),0.15); color: #67e8f9; border: 1px solid rgba(6,182,212,0.25); }

        .actions { display: flex; flex-direction: column; gap: 0.6rem; }
        .btn {
            display: block; width: 100%; padding: 0.7rem; border-radius: 10px;
            font-size: 0.88rem; font-weight: 600; text-decoration: none;
            text-align: center; transition: all 150ms ease; border: none; cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, rgb(var(--hub-emerald)), rgb(5,150,105));
            color: #fff; box-shadow: 0 2px 12px rgba(var(--hub-emerald),0.25);
        }
        .btn-primary:hover { box-shadow: 0 4px 20px rgba(var(--hub-emerald),0.35); transform: translateY(-1px); }
        .btn-danger {
            background: rgba(239,68,68,0.15); color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.25);
        }
        .btn-danger:hover { background: rgba(239,68,68,0.25); }
        .btn-ghost { background: transparent; color: rgba(248,250,252,0.5); font-weight: 500; font-size: 0.82rem; }
        .btn-ghost:hover { color: rgba(248,250,252,0.8); }

        .footer-code { font-size: 0.72rem; color: rgba(248,250,252,0.2); margin-top: 1.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
        </div>

        <h1>Acesso negado</h1>
        <p class="desc">Seu perfil nao tem permissao para acessar esta area.</p>

        @if ($user)
            <div class="user-info">
                <div class="label">Logado como</div>
                <div class="name">{{ $user->name }}</div>
                <div class="email">{{ $user->email }}</div>
                <div class="meta">
                    <span class="pill pill-warn">{{ $role ?? 'sem perfil' }}</span>
                    <span class="pill pill-info">requer {{ $expected }}</span>
                </div>
            </div>
        @endif

        <div class="actions">
            @if ($suggestPanel)
                <a href="{{ $correctPanel }}" class="btn btn-primary">Ir para o meu painel ({{ $correctPanel }})</a>
            @endif
            <a href="{{ $logoutUrl }}" class="btn btn-danger">Sair e logar com outra conta</a>
            <a href="{{ $isApp ? '/app/login' : '/admin/login' }}" class="btn btn-ghost">Voltar para o login</a>
        </div>

        <p class="footer-code">Erro 403 &mdash; Forbidden</p>
    </div>
</body>
</html>
