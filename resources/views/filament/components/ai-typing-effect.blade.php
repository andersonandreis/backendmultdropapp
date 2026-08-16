<div
    x-data
    x-on:ai-type-effect.window="
        const { targetId, text } = $event.detail;
        const el = document.getElementById(targetId);
        if (!el) return;

        const isTextarea = el.tagName === 'TEXTAREA';
        const chunkSize = isTextarea ? 4 : 1;
        const speed = isTextarea ? 12 : 30;
        let i = 0;

        el.value = '';
        el.focus();
        el.style.borderColor = '#6366f1';
        el.style.boxShadow = '0 0 0 3px rgba(99, 102, 241, 0.15)';
        el.style.transition = 'border-color 0.3s, box-shadow 0.3s';

        const timer = setInterval(() => {
            i = Math.min(i + chunkSize, text.length);
            el.value = text.substring(0, i);

            if (isTextarea) {
                el.scrollTop = el.scrollHeight;
            }

            if (i >= text.length) {
                clearInterval(timer);
                setTimeout(() => {
                    el.style.borderColor = '#22c55e';
                    el.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.15)';
                    setTimeout(() => {
                        el.style.borderColor = '';
                        el.style.boxShadow = '';
                    }, 1200);
                }, 100);
                // Sync final value with Livewire
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }, speed);
    "
    class="hidden"
></div>
