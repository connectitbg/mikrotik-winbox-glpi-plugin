(function () {
    'use strict';

    function initializeCountdown() {
        const countdown = document.querySelector('[data-launch-countdown]');
        const button = document.querySelector('[data-launch-button]');
        if (!countdown || !button) {
            return;
        }

        const expiresAt = Number(countdown.dataset.expiresAt);
        const template = countdown.dataset.template || 'Time remaining: %d seconds.';
        const expiredText = countdown.dataset.expiredText || 'Launch code expired.';
        if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
            return;
        }

        let intervalId;
        const update = function () {
            const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            if (remaining > 0) {
                countdown.textContent = template.replace('%d', String(remaining));
                return;
            }

            countdown.textContent = expiredText;
            button.removeAttribute('href');
            button.classList.add('disabled');
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('tabindex', '-1');
            window.clearInterval(intervalId);
        };

        update();
        intervalId = window.setInterval(update, 200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCountdown, { once: true });
    } else {
        initializeCountdown();
    }
}());
