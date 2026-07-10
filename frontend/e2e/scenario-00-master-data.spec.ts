import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md シナリオ0(初期マスタ設定)。
 * ScenarioSeeder が投入する内容を、管理画面から手作業で行っても同じ結果になることを
 * 確認する。
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

test('カレンダー作成〜公開〜勤務形態作成〜シフト生成〜有給付与ルール作成〜手動付与', async ({ page }) => {
  // 既存データ(ScenarioSeederが投入した現在年度のカレンダー等や、このテストの過去の
  // 実行結果)と衝突しないよう、実運用データが使わない範囲(西暦3000年度以降)から
  // 実行のたびにランダムな年度を選ぶ。starts_on/ends_onにそのまま実在の日付として使うため
  // HTML date inputが受け付ける範囲(西暦9999年まで)に収める。
  const fiscalYear = 3000 + Math.floor(Math.random() * 6000)
  const calendarName = `E2Eテスト用カレンダー${fiscalYear}`
  const workStyleCode = `e2e_work_style_${fiscalYear}`

  await loginAs(page, SCENARIO_USERS.admin)

  // --- UC-C001: カレンダー作成〜日別属性登録〜公開 ---
  await page.goto('/admin/work-calendars')
  await page.getByLabel('カレンダー名').fill(calendarName)
  await page.getByLabel('年度').fill(String(fiscalYear))
  await page.getByLabel('開始日', { exact: true }).fill(`${fiscalYear}-04-01`)
  await page.getByLabel('終了日', { exact: true }).fill(`${fiscalYear + 1}-03-31`)
  await page.getByRole('button', { name: '作成する' }).click()

  const calendarRow = page.locator('li', { has: page.getByRole('link', { name: calendarName }) })
  await expect(calendarRow).toBeVisible()
  await expect(calendarRow.getByRole('status', { name: '未公開' })).toBeVisible()

  await calendarRow.getByRole('link', { name: calendarName }).click()
  await expect(page.getByRole('heading', { name: `${calendarName} の日別編集` })).toBeVisible()

  await page.getByRole('button', { name: '行を追加' }).click()
  await page.getByLabel('日付').fill(`${fiscalYear}-04-01`)
  await page.getByLabel('区分').fill('weekday')
  await page.getByRole('button', { name: '保存する' }).click()
  await expect(page.getByRole('button', { name: '保存する' })).not.toBeDisabled()

  await page.goto('/admin/work-calendars')
  await calendarRow.getByRole('button', { name: '公開する' }).click()
  await expect(calendarRow.getByRole('status', { name: '公開済み' })).toBeVisible()

  // --- UC-C002: 勤務形態作成 ---
  await page.goto('/admin/work-styles')
  await page.getByLabel('コード', { exact: true }).fill(workStyleCode)
  await page.getByLabel('名称').fill('E2Eテスト用勤務形態')
  await page.getByLabel('労働時間制').fill('fixed')
  await page.getByLabel('所定労働時間(分/日)').fill('480')
  await page.getByLabel('所定労働時間(分/週)').fill('2400')
  await page.getByLabel('標準開始時刻').fill('09:00')
  await page.getByLabel('標準終了時刻').fill('18:00')
  await page.getByLabel('カレンダー').selectOption({ label: calendarName })
  await page.getByRole('button', { name: '作成する' }).click()
  await expect(page.getByText(workStyleCode)).toBeVisible()

  // --- UC-C003: シフト生成 ---
  await page.getByLabel('対象社員').fill(SCENARIO_USERS.punchEmployee)
  await page.getByRole('button', { name: `${SCENARIO_USERS.punchEmployee}(kenta.takahashi@example.com)` }).click()
  await page.getByLabel('勤務形態').selectOption({ label: 'E2Eテスト用勤務形態' })
  await page.getByLabel('開始日', { exact: true }).fill(`${fiscalYear}-04-01`)
  await page.getByLabel('終了日').fill(`${fiscalYear}-04-01`)
  await page.getByRole('button', { name: '生成する' }).click()
  await expect(page.getByText(`${fiscalYear}-04-01`)).toBeVisible()

  // --- UC-P001: 有給付与ルール作成 ---
  await page.goto('/admin/paid-leave')
  await page.getByLabel('ルール名').fill(`E2Eテスト用付与ルール${fiscalYear}`)
  await page.getByLabel('継続勤務(か月)').fill('6')
  await page.locator('#step-days').fill('10')
  await page.getByRole('button', { name: '追加' }).click()
  await expect(page.getByText('継続勤務6か月→10日').last()).toBeVisible()
  await page.getByRole('button', { name: 'ルールを作成' }).click()
  await expect(page.getByText(`E2Eテスト用付与ルール${fiscalYear}`)).toBeVisible()

  // --- UC-P002: 手動付与 ---
  await page.getByLabel('対象社員').fill(SCENARIO_USERS.monthlyEmployee)
  await page.getByRole('button', { name: `${SCENARIO_USERS.monthlyEmployee}(mai.ito@example.com)` }).click()
  await page.locator('#grant-granted-on').fill(`${fiscalYear}-04-01`)
  await page.locator('#grant-expires-on').fill(`${fiscalYear + 2}-03-31`)
  await page.locator('#grant-granted-days').fill('5')
  await page.getByRole('button', { name: '付与する' }).click()
  await expect(page.getByText(`${fiscalYear}-04-01 〜 ${fiscalYear + 2}-03-31`)).toBeVisible()
})
