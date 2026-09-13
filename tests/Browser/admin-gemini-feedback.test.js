'use strict';

const fs = require('fs');
const vm = require('vm');

const scriptPath = process.argv[2];
if (!scriptPath) throw new Error('Admin Gemini feedback script path is required.');
const source = fs.readFileSync(scriptPath, 'utf8');

function button(label, disabled = false) {
  return { textContent: label, disabled };
}

function form(kind, submitButton) {
  const listeners = new Map();
  const attributes = new Map();
  return {
    dataset: { pendingLabel: kind === 'save' ? 'Salvando…' : 'Testando…' },
    addEventListener(name, listener) { listeners.set(name, listener); },
    querySelector(selector) { return selector === 'button[type="submit"]' ? submitButton : null; },
    setAttribute(name, value) { attributes.set(name, value); },
    listener: (name) => listeners.get(name),
    attribute: (name) => attributes.get(name),
  };
}

function main() {
  const saveButton = button('Salvar configuração');
  const testButton = button('Testar conexão', true);
  const saveForm = form('save', saveButton);
  const testForm = form('test', testButton);
  const status = { textContent: '' };
  const model = { value: 'gemini-test', defaultValue: 'gemini-test' };
  const secret = { value: '' };
  const clear = { checked: false, defaultChecked: false };
  saveForm.querySelector = (selector) => ({
    'button[type="submit"]': saveButton,
    '[name="model"]': model,
    '[name="api_key"]': secret,
    '[name="clear_api_key"]': clear,
  })[selector] || null;
  const document = {
    querySelector(selector) {
      return ({
        '[data-admin-gemini-save]': saveForm,
        '[data-admin-gemini-test]': testForm,
        '[data-admin-gemini-feedback]': status,
      })[selector] || null;
    },
  };

  vm.runInNewContext(source, { document, Map, String });

  if (testButton.disabled) throw new Error('The guarded connection test was not enabled after JavaScript initialized.');

  const saveEvent = { prevented: false, preventDefault() { this.prevented = true; } };
  saveForm.listener('submit')(saveEvent);
  if (saveEvent.prevented || !saveButton.disabled || saveButton.textContent !== 'Salvando…' || saveForm.attribute('aria-busy') !== 'true') {
    throw new Error('Saving feedback was not activated on native form submission.');
  }

  saveButton.disabled = false;
  saveButton.textContent = 'Salvar configuração';
  secret.value = 'unsaved-secret-value';
  const blockedTest = { prevented: false, preventDefault() { this.prevented = true; } };
  testForm.listener('submit')(blockedTest);
  if (!blockedTest.prevented || testButton.disabled || !status.textContent.includes('não salvas')) {
    throw new Error('Testing discarded or submitted unsaved configuration fields.');
  }

  secret.value = '';
  const testEvent = { prevented: false, preventDefault() { this.prevented = true; } };
  testForm.listener('submit')(testEvent);
  if (testEvent.prevented || !testButton.disabled || testButton.textContent !== 'Testando…' || testForm.attribute('aria-busy') !== 'true') {
    throw new Error('Connection-test feedback was not activated for saved configuration.');
  }

  process.stdout.write('ok\n');
}

try {
  main();
} catch (error) {
  process.stderr.write(String(error && error.message ? error.message : error) + '\n');
  process.exitCode = 1;
}
