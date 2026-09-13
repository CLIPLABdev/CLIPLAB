import {defineConfig} from '@playwright/test';
export default defineConfig({testMatch:'thumbnail-publication.spec.mjs',workers:1,use:{channel:process.env.TEST_BROWSER_CHANNEL||'chrome'}});
