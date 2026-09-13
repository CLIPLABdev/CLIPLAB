(() => {
    const toggle = document.querySelector('[data-drawer-toggle]');
    const sidebar = document.querySelector('[data-app-sidebar]');
    const backdrop = document.querySelector('[data-drawer-backdrop]');
    const drawerMedia = window.matchMedia('(max-width: 800px)');
    let opener = null;

    if (window.lucide) window.lucide.createIcons();
    if (!toggle || !sidebar || !backdrop) return;
    document.body.classList.add('workspace-drawer-ready');

    const focusable = () => [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])')];
    const setState = (isOpen, restoreFocus = false) => {
        const isMobile = drawerMedia.matches;
        const open = isMobile && isOpen;
        sidebar.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('drawer-open', open);
        sidebar.inert = isMobile && !open;
        if (isMobile && !open) {
            sidebar.setAttribute('aria-hidden', 'true');
        } else {
            sidebar.removeAttribute('aria-hidden');
        }
        if (restoreFocus) opener?.focus();
    };
    const close = () => {
        setState(false, true);
    };
    const open = () => {
        if (!drawerMedia.matches) return;
        opener = document.activeElement;
        setState(true);
        focusable()[0]?.focus();
    };

    toggle.addEventListener('click', () => sidebar.classList.contains('is-open') ? close() : open());
    backdrop.addEventListener('click', close);
    const syncForViewport = () => setState(false);
    if (drawerMedia.addEventListener) drawerMedia.addEventListener('change', syncForViewport);
    else drawerMedia.addListener(syncForViewport);
    syncForViewport();
    document.addEventListener('keydown', (event) => {
        if (!sidebar.classList.contains('is-open')) return;
        if (event.key === 'Escape') { close(); return; }
        if (event.key !== 'Tab') return;
        const items = focusable();
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
})();
