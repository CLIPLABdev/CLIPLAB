(() => {
    'use strict';
    const dialog = document.querySelector('[data-workspace-dialog]');
    const trigger = document.querySelector('[data-workspace-open]');
    const input = dialog?.querySelector('[data-workspace-search]');
    if (!dialog || !trigger || !input || typeof dialog.showModal !== 'function') return;
    const items = [...dialog.querySelectorAll('[data-workspace-item]')];
    const empty = dialog.querySelector('[data-workspace-empty]');
    const count = dialog.querySelector('[data-workspace-count]');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
    const labels = new Map(items.map(item => [item, normalize(item.textContent)]));
    let opener = trigger;
    const filter = () => {
        const words = normalize(input.value).split(/\s+/).filter(Boolean);
        let visible = 0;
        for (const item of items) {
            item.hidden = !words.every(word => labels.get(item).includes(word));
            if (!item.hidden) visible++;
        }
        empty.hidden = visible > 0;
        count.textContent = words.length ? `${visible} ${visible === 1 ? 'página encontrada' : 'páginas encontradas'}.` : 'Encontre uma página ou comece um novo projeto.';
    };
    const open = () => {
        if (dialog.open || document.querySelector('dialog[open]')) return;
        opener = document.activeElement;
        input.value = '';
        filter();
        dialog.showModal();
        input.focus();
    };
    trigger.hidden = false;
    trigger.addEventListener('click', open);
    input.addEventListener('input', filter);
    dialog.querySelector('[data-workspace-close]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => opener?.focus());
    dialog.addEventListener('click', event => { if (event.target === dialog) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); } });
    dialog.addEventListener('keydown', event => {
        // Search inputs consume Escape to clear text; the palette consistently closes instead.
        if (event.key === 'Escape') { event.preventDefault(); dialog.close(); return; }
        if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
        const links = items.filter(item => !item.hidden).map(item => item.querySelector('a'));
        if (!links.length) return;
        event.preventDefault();
        const current = links.indexOf(document.activeElement);
        const next = event.key === 'ArrowDown' ? (current + 1) % links.length : (current <= 0 ? links.length - 1 : current - 1);
        links[next].focus();
    });
    document.addEventListener('keydown', event => {
        if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k' || event.altKey) return;
        if (event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
        event.preventDefault();
        open();
    });
})();
