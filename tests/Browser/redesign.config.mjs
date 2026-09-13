import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', testMatch: 'redesign.spec.mjs', workers: 1,
  timeout: 25000, expect: { timeout: 4000 }, reporter: 'line',
  outputDir: '../../.superpowers/sdd/2026-09-06-clipforge-redesign/browser',
  use: { baseURL: 'http://127.0.0.1:8093', channel: 'chrome', headless: true },
});
