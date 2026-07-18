import { defineConfig, devices } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md のシナリオを実行するPlaywright E2Eテストの設定。
 *
 * 前提: backend (php artisan serve, http://localhost:8000)、mock-oidc
 * (http://localhost:9000)、frontend (npm run dev, http://localhost:5173) が
 * 起動していること。`globalSetup`(./global-setup.ts)が実行開始時に
 * `POST /dev/reset-database` を1回呼び、DBを`migrate:fresh --seed` +
 * `ScenarioSeeder`の状態に自動でリセットするため、シナリオ用マスタデータ・ユーザーの
 * 投入を手動で行う必要はない(backend側の`.env`で`MICROSOFT_MOCK_ENABLED=true`が
 * 必須)。
 *
 * 実行: cd frontend && npm run test:e2e
 */
export default defineConfig({
  testDir: './',
  testMatch: '**/*.spec.ts',
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_FRONTEND_URL ?? 'http://localhost:5173',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // 環境によってはPlaywrightが同梱するChromiumのリビジョンと実際に
        // インストール済みのバイナリが一致しないことがある。その場合は
        // `npx playwright install chromium` するか、E2E_CHROMIUM_EXECUTABLE_PATH で
        // 既存のChromiumバイナリを直接指定する。
        launchOptions: process.env.E2E_CHROMIUM_EXECUTABLE_PATH
          ? { executablePath: process.env.E2E_CHROMIUM_EXECUTABLE_PATH }
          : {},
      },
    },
  ],
})
