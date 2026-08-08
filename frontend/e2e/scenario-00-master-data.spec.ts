import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { pickDate, pickTime, pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md シナリオ0(初期マスタ設定)。
 * ScenarioSeeder が投入する内容を、管理画面から手作業で行っても同じ結果になることを
 * 確認する。
 */
test('管理者が各種マスタ管理画面にアクセスできる', async ({ page }) => {
  test.setTimeout(120000)
  await loginAs(page, SCENARIO_USERS.admin)

  for (const path of [
    '/admin/work-calendars',
    '/admin/work-styles',
    '/admin/shifts',
    '/admin/paid-leave',
    '/admin/request-types',
    '/admin/users',
    '/admin/access-control',
    '/admin/system-settings',
  ]) {
    await page.goto(path)
    await expect(page.getByRole('main')).toBeVisible()
    await expect(page.getByText('読み込み中...')).toHaveCount(0)
  }
})

test('カレンダー作成〜公開〜勤務形態作成〜シフト生成〜有給付与ルール作成〜手動付与', async ({ page }) => {
  test.setTimeout(180000)
  // 既存データ(ScenarioSeederが投入した現在年度のカレンダー等や、このテストの過去の
  // 実行結果)と衝突しないよう、実運用データが使わない範囲(西暦3000年度以降)から
  // 実行のたびにランダムな年度を選ぶ。starts_on/ends_onにそのまま実在の日付として使うため
  // HTML date inputが受け付ける範囲(西暦9999年まで)に収める。
  const fiscalYear = new Date().getFullYear() + 2
  const calendarName = `E2Eテスト用カレンダー${fiscalYear}`
  const workStyleCode = `e2e_work_style_${fiscalYear}`

  await loginAs(page, SCENARIO_USERS.admin)

  // --- UC-C001: カレンダー作成〜日別属性登録〜公開 ---
  await page.goto('/admin/work-calendars')
  await page.getByLabel('カレンダー名').fill(calendarName)
  await page.getByLabel('年度').fill(String(fiscalYear))
  await pickDate(page, '開始日', `${fiscalYear}-04-01`, { exact: true })
  await pickDate(page, '終了日', `${fiscalYear + 1}-03-31`, { exact: true })
  await page.getByRole('button', { name: '作成する' }).click()

  const calendarRow = page.locator('li', { has: page.getByRole('link', { name: calendarName }) })
  await expect(calendarRow).toBeVisible()
  await expect(calendarRow.getByRole('status', { name: '未公開' })).toBeVisible()

  await calendarRow.getByRole('link', { name: calendarName }).click()
  await expect(page.getByRole('heading', { name: `${calendarName} の日別編集` })).toBeVisible()

  await page.getByRole('button', { name: '行を追加' }).click()
  await pickDate(page, '日付', `${fiscalYear}-04-01`)
  await page.getByLabel('区分').fill('weekday')
  await page.getByRole('button', { name: '保存する' }).click()
  await expect(page.getByRole('button', { name: '保存する' })).not.toBeDisabled()

  await page.goto('/admin/work-calendars')
  await calendarRow.getByRole('button', { name: '公開する' }).click()
  await expect(calendarRow.getByRole('status', { name: '公開済み' })).toBeVisible()

  // --- UC-C002: 勤務形態作成(一覧のモーダル経由) ---
  await page.goto('/admin/work-styles')
  await page.getByRole('button', { name: '新規登録' }).click()
  await page.getByLabel('コード').fill(workStyleCode)
  await page.getByLabel('名称').fill('E2Eテスト用勤務形態')
  await page.getByLabel('労働時間制').selectOption({ value: 'fixed' })
  await page.getByLabel('所定労働時間(分/日)').fill('480')
  await page.getByLabel('所定労働時間(分/週)').fill('2400')
  await pickTime(page, '標準開始時刻', '09:00')
  await pickTime(page, '標準終了時刻', '18:00')
  await page.getByLabel('カレンダー').selectOption({ label: calendarName })
  await page.getByRole('button', { name: '登録する' }).click()
  await expect(page.getByText(workStyleCode)).toBeVisible()

  // --- UC-C003: シフト生成(シフトページ) ---
  await page.goto('/admin/shifts')
  await pickUser(page, '対象社員', SCENARIO_USERS.punchEmployee, 'kenta.takahashi@example.com', { exact: true })
  await page.getByLabel('勤務形態', { exact: true }).selectOption({ label: 'E2Eテスト用勤務形態' })
  await pickDate(page, '開始日', `${fiscalYear}-04-01`, { exact: true })
  await pickDate(page, '終了日', `${fiscalYear}-04-01`, { exact: true })
  const generateButton = page.getByRole('button', { name: '生成する', exact: true })
  await generateButton.click()
  await expect(generateButton).toBeEnabled()
  await expect(page.getByRole('heading', { name: 'シフト一覧' }).locator('..')).toContainText(`${fiscalYear}-04-01`)

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
  await pickUser(page, '対象社員', SCENARIO_USERS.monthlyEmployee, 'mai.ito@example.com', { exact: true })
  await pickDate(page, '付与日', `${fiscalYear}-04-01`, { exact: true })
  await pickDate(page, '失効日', `${fiscalYear + 2}-03-31`, { exact: true })
  await page.locator('#grant-granted-days').fill('5')
  await page.getByRole('button', { name: '付与する' }).click()
  await expect(page.getByText(`${fiscalYear}-04-01 〜 ${fiscalYear + 2}-03-31`)).toBeVisible()
})
