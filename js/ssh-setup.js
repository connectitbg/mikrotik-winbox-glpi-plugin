document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-target]');
    if (!button) {
        return;
    }

    const field = document.getElementById(button.dataset.copyTarget);
    if (!(field instanceof HTMLTextAreaElement)) {
        return;
    }

    field.focus();
    field.select();
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(field.value);
        } else {
            document.execCommand('copy');
        }
        button.dataset.originalLabel ??= button.textContent;
        button.textContent = button.dataset.copiedLabel || 'Copied';
        window.setTimeout(() => {
            button.textContent = button.dataset.originalLabel;
        }, 1800);
    } catch {
        // Keep the text selected so the user can use Ctrl+C.
    }
});
