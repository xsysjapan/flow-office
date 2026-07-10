import { test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md シナリオ0(初期マスタ設定)。
 * ScenarioSeeder が投入する内容を、管理画面から手作業で行っても同じ結果になることを
 * 確認するテスト。まずは「管理者でログインでき、各管理画面が開けること」だけを
 * 実装し、カレンダー作成〜勤務形態作成〜有給付与ルール作成の詳細操作は
 * 画面のフォーム項目確定後に追記する(TODO)。
 */
test('管理者が各種マスタ管理画面にアクセスできる', async ({ page }) => {
  await loginAs(page, SCENARIO_USERS.admin)

  for (const path of [
    '/admin/work-calendars',
    '/admin/work-styles',
    '/admin/paid-leave',
    '/admin/request-types',
    '/admin/users',
    '/admin/system-settings',
  ]) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
  }
})

test.skip('カレンダー作成〜公開〜勤務形態作成〜有給付与ルール作成 (TODO)', async () => {
  // docs/testing/scenario-tests.md シナリオ0 手順2〜5を画面操作で実装する。
})
