@props(['field', 'recordId'])

<div
    x-data="aiStreamButton({ field: @js($field), recordId: @js((int) $recordId) })"
    class="flex items-center gap-3"
>
    <button
        type="button"
        x-on:click="generate()"
        x-bind:disabled="loading"
        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium
               text-info-700 bg-info-50 hover:bg-info-100
               dark:text-info-300 dark:bg-info-500/10 dark:hover:bg-info-500/20
               border border-info-200 dark:border-info-500/30
               transition disabled:opacity-50 disabled:cursor-not-allowed"
    >
        <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.847.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
        </svg>
        <svg x-show="loading" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <span x-text="loading ? 'Gerando…' : @js('Gerar ' . ($field === 'title' ? 'título' : 'descrição') . ' com IA')"></span>
    </button>

    <span x-show="error" x-cloak x-text="error" class="text-xs text-danger-600"></span>
</div>

<script>
if (!window.aiStreamButton) {
    window.aiStreamButton = function ({ field, recordId }) {
        return {
            loading: false,
            error: null,

            async generate() {
                if (this.loading) return;
                this.loading = true;
                this.error = null;

                const fieldKey = field === 'title' ? 'custom_title' : 'custom_description';
                const dataPath = `data.${fieldKey}`;

                // Limpa o campo pra começar do zero (typewriter visível)
                await this.$wire.set(dataPath, '');

                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

                try {
                    const resp = await fetch('/app/ai/generate-announcement-field', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'text/event-stream',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            client_product_id: recordId,
                            field: field,
                        }),
                    });

                    if (!resp.ok) {
                        const txt = await resp.text();
                        throw new Error(`HTTP ${resp.status}: ${txt.slice(0, 120)}`);
                    }

                    const reader = resp.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let accumulated = '';
                    let lastFlush = 0;

                    while (true) {
                        const { value, done } = await reader.read();
                        if (done) break;

                        buffer += decoder.decode(value, { stream: true });

                        // SSE: blocos separados por \n\n
                        const blocks = buffer.split('\n\n');
                        buffer = blocks.pop(); // último pode estar incompleto

                        for (const block of blocks) {
                            const m = block.match(/^data: ?(.*)$/);
                            if (!m) continue;
                            const chunk = m[1];
                            if (chunk === '[DONE]') {
                                await this.$wire.set(dataPath, accumulated);
                                return;
                            }
                            accumulated += chunk;

                            // Throttle update — $wire.set a cada ~60ms
                            const now = performance.now();
                            if (now - lastFlush > 60) {
                                this.$wire.set(dataPath, accumulated);
                                lastFlush = now;
                            }
                        }
                    }
                    this.$wire.set(dataPath, accumulated);
                } catch (e) {
                    this.error = e.message || 'Erro ao gerar';
                    console.error('[aiStream]', e);
                } finally {
                    this.loading = false;
                }
            },
        };
    };
}
</script>
