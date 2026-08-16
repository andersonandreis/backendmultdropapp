<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>@yield('title', 'HubAI')</title>
    <style>
        /* Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f4f6f9;
            color: #1a1a2e;
            -webkit-text-size-adjust: 100%;
        }
        a { color: inherit; text-decoration: none; }
        img { border: 0; display: block; }
        /* Wrapper */
        .email-wrapper {
            width: 100%;
            background-color: #f4f6f9;
            padding: 32px 16px;
        }
        /* Container */
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        /* Header */
        .email-header {
            background: linear-gradient(135deg, #6c63ff 0%, #3f3d8f 100%);
            padding: 32px 40px;
            text-align: center;
        }
        .email-header img {
            height: 40px;
            margin: 0 auto;
        }
        /* Body */
        .email-body {
            padding: 40px 40px 32px;
        }
        /* Footer */
        .email-footer {
            background-color: #f8f9fc;
            border-top: 1px solid #eaedf3;
            padding: 24px 40px;
            text-align: center;
        }
        .email-footer p {
            font-size: 12px;
            color: #8a8fa8;
            line-height: 1.6;
        }
        .email-footer a {
            color: #6c63ff;
            text-decoration: underline;
        }
        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-body { padding: 24px 20px 20px; }
            .email-header { padding: 24px 20px; }
            .email-footer { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <!-- Header -->
            <div class="email-header">
                <img
                    src="{{ asset('images/logo-white.png') }}"
                    alt="HubAI"
                    onerror="this.style.display='none'"
                />
                @if(empty(asset('images/logo-white.png')))
                    <span style="color:#ffffff;font-size:26px;font-weight:700;letter-spacing:-0.5px;">Hub<span style="color:#c4b5fd;">AI</span></span>
                @endif
            </div>

            <!-- Body -->
            <div class="email-body">
                @yield('content')
            </div>

            <!-- Footer -->
            <div class="email-footer">
                @yield('footer')
                <p style="margin-top:12px;">
                    HubAI &mdash; Plataforma de Dropshipping Inteligente<br />
                    Voce esta recebendo este e-mail porque se cadastrou em <a href="https://fornecefy.io">fornecefy.io</a>.
                </p>
                @if(isset($emailLog))
                    <p style="margin-top:8px;">
                        <a href="{{ $emailLog->trackedUrl('https://fornecefy.io/unsubscribe') }}">
                            Cancelar inscricao
                        </a>
                    </p>
                @endif
                <!-- Pixel de rastreamento -->
                @if(isset($emailLog))
                    <img src="{{ $emailLog->trackingPixelUrl() }}" width="1" height="1" alt="" style="display:none;" />
                @endif
            </div>
        </div>
    </div>
</body>
</html>
