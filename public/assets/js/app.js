(() => {
    const toggle = document.querySelector('[data-nav-toggle]');
    const menu = document.querySelector('[data-nav-menu]');
    const header = document.querySelector('[data-header]');
    if (window.lucide) window.lucide.createIcons();
    if (toggle && menu) {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                toggle.setAttribute('aria-expanded', 'false');
                menu.classList.remove('is-open');
                toggle.focus();
            }
        });
        toggle.addEventListener('click', () => { const isOpen = toggle.getAttribute('aria-expanded') === 'true'; toggle.setAttribute('aria-expanded', String(!isOpen)); menu.classList.toggle('is-open', !isOpen); });
        menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => { toggle.setAttribute('aria-expanded', 'false'); menu.classList.remove('is-open'); }));
    }
    const setHeaderState = () => header?.classList.toggle('is-scrolled', window.scrollY > 8);
    setHeaderState(); window.addEventListener('scroll', setHeaderState, { passive: true });
})();
