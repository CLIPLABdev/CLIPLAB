(() => {
    'use strict';
    const locked = new WeakSet();
    document.querySelectorAll('form[data-platform-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            if (locked.has(form)) { event.preventDefault(); return; }
            if (!form.checkValidity()) return;
            locked.add(form);
            form.setAttribute('aria-busy', 'true');
            // Keep successful controls enabled so submitter names/values still reach the server.
            const feedback = document.createElement('p');
            feedback.className = 'platform-submitting';
            feedback.setAttribute('role', 'status');
            feedback.textContent = event.submitter?.dataset.submitLabel || 'Processando sua solicitação…';
            form.append(feedback);
        });
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-platform-form]').forEach((form) => {
            locked.delete(form);
            form.removeAttribute('aria-busy');
            form.querySelectorAll('.platform-submitting').forEach((node) => node.remove());
        });
    });
    let popupShown = false;
    document.querySelectorAll('[data-promotion-key]').forEach((notice) => {
        const key = `clipforge:notice:${notice.dataset.promotionKey}`;
        let dismissed = false;
        try { dismissed = window.localStorage.getItem(key) === 'dismissed'; } catch (_) { /* Private browsing still supports dismissal in this page. */ }
        if (dismissed) { notice.hidden = true; return; }
        let dialog = null;
        const dismiss = () => {
            if (dialog?.open) dialog.close();
            notice.hidden = true;
            try { window.localStorage.setItem(key, 'dismissed'); } catch (_) { /* Storage is optional. */ }
        };
        notice.querySelector('[data-promotion-dismiss]')?.addEventListener('click', dismiss);
        if (notice.dataset.promotionKind === 'popup' && !popupShown && typeof HTMLDialogElement !== 'undefined') {
            popupShown = true;
            dialog = document.createElement('dialog');
            dialog.className = 'platform-dialog';
            dialog.setAttribute('aria-label', 'Comunicado da plataforma');
            notice.before(dialog);
            dialog.append(notice);
            dialog.addEventListener('cancel', () => dismiss());
            dialog.showModal();
        }
    });
})();
