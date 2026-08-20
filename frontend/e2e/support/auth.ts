import type { Page } from '@playwright/test'

/**
 * docs/testing/scenario-tests.md §3 の登場人物。
 * `backend/database/seeders/ScenarioSeeder.php` と `mock-oidc/server.js` の
 * 追加ユーザーに対応する表示名を1か所にまとめる。
 */
export const SCENARIO_USERS = {
  punchEmployee: '高橋 健太',
  monthlyEmployee: '伊藤 舞',
  approver: '渡辺 直樹',
  accountingStaff: '小林 誠',
  generalAffairsStaff: '中村 恵',
  hrStaff: '加藤 由美',
  admin: 'Test Admin',
} as const

export type ScenarioUserKey = keyof typeof SCENARIO_USERS

/**
 * UC-001: モックOIDCのログイン画面で指定した表示名のユーザーを選択してログインする。
 * 実際のEntra IDと異なりPKCE等の検証がないため、ボタンクリックのみで完結する。
 */
export async function loginAs(page: Page, displayName: string): Promise<void> {
  const frontendOrigin = new URL(process.env.E2E_FRONTEND_URL ?? 'http://localhost:5173').origin
  const mockOidcOrigin = new URL(process.env.E2E_MOCK_OIDC_URL ?? 'http://localhost:9000').origin

  await page.goto('/login')
  await page.getByRole('button', { name: 'Microsoftでログイン' }).click()

  // frontend が /api/auth/microsoft/redirect の結果でモックOIDCのログイン画面へ遷移する。
  await page.waitForURL((url) => url.origin === mockOidcOrigin && url.pathname === '/oauth2/v2.0/authorize')
  await page
    .locator('form', { has: page.locator(`strong:text-is("${displayName}")`) })
    .locator('button[type="submit"]')
    .click()

  // モックOIDC → backendコールバック → frontendの /auth/callback を経てトップ画面に戻る。
  await page.waitForURL(
    (url) =>
      url.origin === frontendOrigin &&
      !url.pathname.startsWith('/auth/callback') &&
      !url.pathname.startsWith('/login'),
  )
  await page.waitForFunction(() => Boolean(localStorage.getItem('flow-office.token')))
}
