(() => {
    const saveForm = document.querySelector('[data-admin-gemini-save]');
    const testForm = document.querySelector('[data-admin-gemini-test]');
    const feedback = document.querySelector('[data-admin-gemini-feedback]');
    if (!saveForm || !testForm) return;

    const beginSubmission = (form, message) => {
        if (form.dataset.submitting === 'true') return false;
        const button = form.querySelector('button[type="submit"]');
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        if (button) {
            button.disabled = true;
            button.textContent = form.dataset.pendingLabel || message;
        }
        if (feedback) feedback.textContent = message;
        return true;
    };

    const hasUnsavedConfiguration = () => {
        const model = saveForm.querySelector('[name="model"]');
        const secret = saveForm.querySelector('[name="api_key"]');
        const clear = saveForm.querySelector('[name="clear_api_key"]');
        return Boolean(
            (model && model.value !== model.defaultValue)
            || (secret && secret.value !== '')
            || (clear && clear.checked !== clear.defaultChecked)
        );
    };

    saveForm.addEventListener('submit', (event) => {
        if (!beginSubmission(saveForm, 'Salvando a configuração Gemini…')) event.preventDefault();
    });

    testForm.addEventListener('submit', (event) => {
        if (hasUnsavedConfiguration()) {
            event.preventDefault();
            if (feedback) feedback.textContent = 'Há alterações não salvas. Salve-as antes de testar; nenhum campo foi descartado ou enviado pelo teste.';
            return;
        }
        if (!beginSubmission(testForm, 'Testando a configuração Gemini já salva…')) event.preventDefault();
    });

    const guardedTestButton = testForm.querySelector('button[type="submit"]');
    if (guardedTestButton) guardedTestButton.disabled = false;
})();
