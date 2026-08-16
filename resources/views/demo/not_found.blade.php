<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Demo nao encontrada - Fornecefy</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; background: #080808; color: #f1f1f1; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; }
    .center { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; gap: 16px; }
    .logo { font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 8px; }
    .logo span { color: #FE2C55; }
    .icon { width: 64px; height: 64px; border-radius: 18px; background: rgba(254,44,85,0.08); border: 1px solid rgba(254,44,85,0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto 4px; }
    h1 { font-size: 20px; font-weight: 700; }
    p { font-size: 13px; color: #666; max-width: 280px; line-height: 1.6; }
    code { font-size: 12px; color: #888; background: #111; padding: 2px 7px; border-radius: 6px; font-family: monospace; }
    a.btn { display: inline-flex; align-items: center; gap: 6px; background: #FE2C55; color: #fff; font-size: 13px; font-weight: 700; padding: 10px 22px; border-radius: 10px; text-decoration: none; margin-top: 8px; }
    a.btn:hover { background: #e0253c; }
  </style>
</head>
<body>
  <div class="center">
    <div class="logo">Fornece<span>fy</span></div>
    <div class="icon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#FE2C55" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <h1>Demo nao encontrada</h1>
    <p>O link <code>/demo/{{ $slug }}</code> nao corresponde a nenhuma demo configurada.</p>
    <p>Se voce e vendedor, acesse a plataforma e configure seu link demo.</p>
    <a class="btn" href="https://fornecefy.io">Ir para Fornecefy</a>
  </div>
</body>
</html>
