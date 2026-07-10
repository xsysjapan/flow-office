import { defineConfig, devices } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md のシナリオを実行するPlaywright E2Eテストの設定。
 *
 * 前提: backend (php artisan serve, http://localhost:8000)、mock-oidc
 * (http://localhost:9000)、frontend (npm run dev, http://localhost:5173) が
 * 起動していること。`ScenarioSeeder` (backend/database/seeders/ScenarioSeeder.php)
 * を一度実行してシナリオ用マスタデータ・ユーザーを投入してから実行する。
 *
 * 実行: cd frontend && npm run test:e2e
 */
export default defineConfig({
  testDir: './',
  testMatch: '**/*.spec.ts',
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
