import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';
import { sessions } from './tests/Browser/support/sessions.js';

const envFile = fileURLToPath(new URL('.env.browser', import.meta.url));

if (existsSync(envFile)) {
    process.loadEnvFile(envFile);
}

export default defineConfig({
    testDir: 'tests/Browser',
    testMatch: '**/*.spec.js',
    globalSetup: './tests/Browser/global-setup.js',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    forbidOnly: !!process.env.CI,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.SANDBOX_URL,
        storageState: sessions.admin,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
