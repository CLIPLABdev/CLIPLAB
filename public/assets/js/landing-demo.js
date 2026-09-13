(() => {
    document.querySelectorAll('[data-landing-demo]').forEach((demo) => {
        const stages = Array.from(demo.querySelectorAll('[data-demo-stage]'));
        const selectors = Array.from(demo.querySelectorAll('[data-demo-select]'));
        const previous = demo.querySelector('[data-demo-prev]');
        const next = demo.querySelector('[data-demo-next]');
        const status = demo.querySelector('[data-demo-status]');
        const names = ['origem', 'sugestões', 'exportação'];
        let current = 0;
        const show = (index) => {
            current = Math.max(0, Math.min(stages.length - 1, index));
            stages.forEach((stage, i) => { stage.hidden = i !== current; });
            selectors.forEach((button, i) => {
                if (i === current) button.setAttribute('aria-current', 'step');
                else button.removeAttribute('aria-current');
            });
            if (previous) previous.disabled = current === 0;
            if (next) next.disabled = current === stages.length - 1;
            if (status) status.textContent = `Etapa ${current + 1} de ${stages.length}: ${names[current]}.`;
        };
        previous?.addEventListener('click', () => show(current - 1));
        next?.addEventListener('click', () => show(current + 1));
        selectors.forEach((button, index) => button.addEventListener('click', () => show(index)));
        show(0);
    });
})();
