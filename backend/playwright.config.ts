import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    forbidOnly: true,
    retries: process.env.CI === 'true' ? 1 : 0,
    workers: process.env.CI === 'true' ? 1 : undefined,
    timeout: 30_000,
    reporter: process.env.CI === 'true' ? 'github' : 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            grepInvert: /Chromium virtual passkey lifecycle|Mobile Player screenshot regression/,
        },
        {
            name: 'chromium-passkey',
            use: { ...devices['Desktop Chrome'], baseURL: 'http://localhost:8000' },
            grep: /Chromium virtual passkey lifecycle/,
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] },
            grepInvert: /Chromium (pairs Presentation|screenshot regression|virtual passkey lifecycle)|Mobile Player screenshot regression/,
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] },
            grepInvert: /Chromium (pairs Presentation|screenshot regression|virtual passkey lifecycle)|Mobile Player screenshot regression/,
        },
        {
            name: 'mobile-chromium',
            use: { ...devices['Pixel 7'] },
            grepInvert: /Chromium (pairs Presentation|screenshot regression|virtual passkey lifecycle)/,
        },
        {
            name: 'mobile-webkit',
            use: { ...devices['iPhone 13'] },
            grepInvert: /Chromium (pairs Presentation|screenshot regression|virtual passkey lifecycle)/,
        },
    ],
});
