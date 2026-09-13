import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', testMatch: 'clip-editor-e2e.spec.mjs', workers: 1, fullyParallel: false,
  timeout: 30000, reporter: 'line',
  outputDir: '../../.superpowers/sdd/2026-09-06-conclusao-saas/editor-browser-results',
  use: { channel: 'chrome', headless: true },
});
