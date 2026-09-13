import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', testMatch: 'project-intake-feedback.spec.mjs', workers: 1,
  timeout: 20000, reporter: 'line',
  outputDir: '../../.superpowers/sdd/2026-09-06-form-fixes/browser',
  use: { channel: 'chrome', headless: true },
});
