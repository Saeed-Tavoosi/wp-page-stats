(() => {
    const form = document.getElementById('wps-update-check');
    const button = document.querySelector('.wps-update-button[form="wps-update-check"]');
    const actions = button?.closest('.row-actions');
    if (!form || !actions || !window.ajaxurl || !window.fetch) return;

    const result = document.createElement('div');
    result.className = 'wps-update-result';
    result.setAttribute('role', 'status');
    actions.after(result);

    function showResult(state, message, detail = '', upgradeUrl = '') {
        result.dataset.state = state;
        result.textContent = message;
        if (upgradeUrl) {
            const link = document.createElement('a');
            link.href = upgradeUrl;
            link.textContent = 'به‌روزرسانی';
            result.append(link);
        }
        if (detail) {
            const description = document.createElement('span');
            description.className = 'wps-update-detail';
            description.dir = 'auto';
            description.textContent = detail;
            result.append(description);
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.getAttribute('aria-busy') === 'true') return;

        button.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');
        showResult('checking', 'در حال بررسی به‌روزرسانی…');

        try {
            const response = await fetch(window.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams(new FormData(form)),
            });
            const payload = await response.json();
            if (typeof payload?.data?.message !== 'string') throw new Error('Invalid response');
            const { message, detail, upgrade_url: upgradeUrl } = payload.data;
            const succeeded = response.ok && payload.success === true;
            showResult(succeeded ? 'success' : 'error', message, detail, succeeded ? upgradeUrl : '');
        } catch {
            showResult('error', 'پاسخ بررسی از وردپرس دریافت نشد. صفحه را تازه کنید و دوباره امتحان کنید.');
        } finally {
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
        }
    });
})();
