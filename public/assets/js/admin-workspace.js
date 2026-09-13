(() => {
    'use strict';
    const body = document.body;
    const drawer = document.querySelector('[data-admin-drawer]');
    const toggle = document.querySelector('[data-admin-drawer-toggle]');
    const close = document.querySelector('[data-admin-drawer-close]');
    const backdrop = document.querySelector('[data-admin-drawer-backdrop]');
    const main = document.querySelector('.admin-main-wrap');
    if (!drawer || !toggle || !close || !backdrop || !main) return;
    const mobile = window.matchMedia('(max-width: 980px)');
    let opened = false;
    let previousFocus = null;
    const focusable = () => Array.from(drawer.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')).filter(el => el.getClientRects().length);
    function setOpen(next, restoreFocus = true) {
        opened = next && mobile.matches;
        body.classList.toggle('admin-drawer-open', opened);
        toggle.setAttribute('aria-expanded', String(opened));
        toggle.setAttribute('aria-label', opened ? 'Fechar navegação' : 'Abrir navegação');
        backdrop.hidden = !opened;
        drawer.inert = mobile.matches && !opened;
        main.inert = opened;
        if (opened) {
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
            close.focus();
        } else {
            drawer.removeAttribute('role');
            drawer.removeAttribute('aria-modal');
            if (restoreFocus && previousFocus?.isConnected && mobile.matches) previousFocus.focus();
        }
    }
    toggle.addEventListener('click', () => {
        previousFocus = document.activeElement;
        setOpen(!opened);
    });
    close.addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', event => {
        if (!opened) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false); return; }
        if (event.key !== 'Tab') return;
        const items = focusable();
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) {
            event.preventDefault(); first?.focus();
        }
    });
    mobile.addEventListener('change', () => {
        const focusWasInside = drawer.contains(document.activeElement);
        setOpen(false, false);
        if (mobile.matches && focusWasInside) toggle.focus();
        if (!mobile.matches && focusWasInside && document.activeElement === close) {
            (drawer.querySelector('a[aria-current="page"]') || drawer.querySelector('a[href]'))?.focus();
        }
    });
    body.classList.add('admin-enhanced');
    setOpen(false, false);
    if (window.lucide) window.lucide.createIcons();
})();
