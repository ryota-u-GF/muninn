import { defineConfig, devices } from '@playwright/test';

/**
 * 主要フローのブラウザ試験。開発用DB（デモデータ）を毎回作り直すため、
 * local 環境の Sail コンテナ内で実行する：composer test:e2e
 */
export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost',
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
    ],
});
