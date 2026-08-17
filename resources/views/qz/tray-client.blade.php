{{-- HUB-QZ 2026-07-17: injetado no layout /admin. Carrega qz-tray.js CDN,
     conecta websocket local do QZ Tray e configura auth via /admin/qz/certificate + /admin/qz/sign.
     Escuta evento Livewire 'qz-print-label' disparado no ScanShipment/PickingPacking.
     Após qz.print resolver, chama POST /admin/qz/mark-printed pra gravar orders.label_printed_at. --}}
{{-- MUL-378 17/08/2026: este bloco derrubava as telas de expedicao inteiras.
     Eram tres <script src> BLOQUEANTES do jsdelivr, injetados ANTES do
     livewire.min.js no BODY_END. As duas dependencias NAO EXISTEM na tag
     v2.2.4 (o repo qzind/tray so publica /js/qz-tray.js): o jsdelivr devolvia
     uma pagina de erro, o Chrome barrava com ERR_BLOCKED_BY_ORB, o parser
     travava naquela tag e o livewire.min.js nunca executava. Resultado medido
     no navegador em 17/08: window.Livewire undefined, Alpine undefined, 3
     componentes no DOM sem bootar — o botao "Buscar Pedido" caia em submit GET
     e a tela nao fazia nada. Bate com o historico: a injecao do QZ e de
     17/07/2026 e o ultimo bip do sistema foi 22/07.
     Nem precisavam existir: o qz-tray.js 2.2.4 tem SHA-256 proprio
     (_qz.SHA.hash) e cai no Promise nativo quando RSVP falta.
     Agora e um arquivo unico, do nosso dominio (public/js/qz/qz-tray.js) —
     sem CDN externo na tela de expedicao. Se algum dia faltar, o 404 nao
     trava o parser e o guard abaixo apenas avisa no console. --}}
<script src="{{ asset('js/qz/qz-tray.js') }}"></script>
<script>
(function () {
    if (!window.qz) {
        console.warn('[QzTray] qz-tray.js não carregou');
        return;
    }

    // CSRF token pra POST mark-printed
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // --- Auth: certificado + assinatura via nossos endpoints ---
    qz.security.setCertificatePromise(function (resolve, reject) {
        fetch('/admin/qz/certificate', { cache: 'no-store', credentials: 'same-origin' })
            .then(r => r.ok ? r.text().then(resolve) : r.text().then(reject));
    });

    qz.security.setSignatureAlgorithm('SHA512');
    qz.security.setSignaturePromise(function (toSign) {
        return function (resolve, reject) {
            fetch('/admin/qz/sign?request=' + encodeURIComponent(toSign),
                { cache: 'no-store', credentials: 'same-origin' })
                .then(r => r.ok ? r.text().then(resolve) : r.text().then(reject));
        };
    });

    // --- Conexão preguiçosa: só conecta na primeira necessidade ---
    // Single-flight: duas chamadas simultâneas de connect() deixam o socket
    // meio-aberto ("sendData is not a function") — compartilhar a mesma promise.
    let connectPromise = null;
    async function ensureConnected(force = false) {
        if (force && qz.websocket.isActive()) {
            try { await qz.websocket.disconnect(); } catch (e) { /* segue */ }
            connectPromise = null;
        }
        if (!force && qz.websocket.isActive()) return;
        if (!connectPromise) {
            connectPromise = qz.websocket.connect({ retries: 2, delay: 1 })
                .then(() => console.info('[QzTray] conectado'))
                .catch((e) => {
                    connectPromise = null;
                    console.warn('[QzTray] offline no PC do usuário — imprima manualmente. Erro:', e);
                    throw e;
                });
        }
        await connectPromise;
    }
    window.qzEnsureConnected = ensureConnected;

    // Etiqueta pode ser PDF (ML), PNG (Shopee) ou JPG (Bling) — detectar pelos
    // magic bytes; mandar PNG como 'pdf' faz o QZ falhar com "Cannot parse".
    function detectFormat(bytes) {
        if (bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) return 'pdf';   // %PDF
        if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4E && bytes[3] === 0x47) return 'image'; // PNG
        if (bytes[0] === 0xFF && bytes[1] === 0xD8) return 'image'; // JPEG
        return 'pdf';
    }

    // --- Grava label_printed_at após impressão bem-sucedida ---
    async function markPrinted({ orderId, orderNumber, printer }) {
        try {
            const resp = await fetch('/admin/qz/mark-printed', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ order_id: orderId, order_number: orderNumber, printer }),
            });
            if (!resp.ok) console.warn('[QzTray] mark-printed HTTP ' + resp.status);
        } catch (e) {
            console.warn('[QzTray] mark-printed erro:', e);
        }
    }

    // --- Exposto pra Livewire chamar ---
    window.qzPrintLabelPdf = async function ({ orderId, printer, labelUrl, orderNumber }) {
        if (!printer) {
            console.warn('[QzTray] impressora padrão não configurada — pular auto-print');
            return { ok: false, reason: 'no_printer' };
        }
        if (!labelUrl) {
            console.warn('[QzTray] label_url vazio — pular');
            return { ok: false, reason: 'no_label' };
        }

        try {
            await ensureConnected();

            const pdfResp = await fetch(labelUrl, { credentials: 'same-origin' });
            if (!pdfResp.ok) throw new Error('fetch label ' + pdfResp.status);
            const buf = await pdfResp.arrayBuffer();
            const bytes = new Uint8Array(buf);
            let bin = '';
            for (let i = 0; i < bytes.length; i += 0x8000) {
                bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
            }
            const b64 = btoa(bin);
            const format = detectFormat(bytes);

            const config = qz.configs.create(printer, { colorType: 'blackwhite' });
            const payload = [{ type: 'pixel', format, flavor: 'base64', data: b64 }];
            try {
                await qz.print(config, payload);
            } catch (e) {
                if (String(e).includes('sendData')) {
                    await ensureConnected(true);
                    await qz.print(config, payload);
                } else {
                    throw e;
                }
            }

            console.info('[QzTray] etiqueta impressa pedido #' + orderNumber);

            // Marca no banco: label_printed_at
            markPrinted({ orderId, orderNumber, printer });

            if (window.$wireui?.notify) {
                window.$wireui.notify({ title: 'Etiqueta impressa', icon: 'success' });
            }
            return { ok: true };
        } catch (e) {
            console.error('[QzTray] falha impressão:', e);
            return { ok: false, reason: String(e) };
        }
    };

    // --- Ouvinte Livewire: espera evento 'qz-print-label' do ScanShipment/PickingPacking ---
    document.addEventListener('livewire:init', () => {
        Livewire.on('qz-print-label', (payload) => {
            const params = Array.isArray(payload) ? payload[0] : payload;
            window.qzPrintLabelPdf(params);
        });
    });
})();
</script>
