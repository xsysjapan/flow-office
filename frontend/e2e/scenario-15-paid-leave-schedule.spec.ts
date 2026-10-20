import { execSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { apiFetch } from './support/api'

/**
 * docs/testing/scenario-tests.md §18(付与予定の確認から一括付与まで)。
 *
 * 有給休暇画面(`/admin/paid-leave`)の「付与ルール」カードの「付与予定を確認」から
 * 付与予定画面(`/admin/paid-leave/schedule`)へ進み、絞り込み(URLに残る)・詳細パネルの
 * 再判定と判定結果の上書き・確認ダイアログを経た一括付与、を画面操作で確認する。
 *
 * 区分・日数の個別修正(§18の手順8)は後続の段階2で追加するため、ここでは扱わない。
 * 部分失敗(対象外の行を含む一括付与)は画面では対象外の行を選べないためAPIで確認する。
 * 付与予定日超過(overdue)の件数は、データの有無で分岐して確認する(超過データを
 * E2Eの中で作る手段が無いため。超過が無い環境では警告が出ないことを確認する)。
 */

const CURRENT_DIR = path.dirname(fileURLToPath(import.meta.url))
const BACKEND_DIR = path.resolve(CURRENT_DIR, '../../backend')

/** 通常付与区分(週5日勤務)を想定した対象社員。 */
const REGULAR_EMPLOYEE_NAME = SCENARIO_USERS.punchEmployee

interface ScheduleEntryRow {
  id: string
  user_name: string | null
  scheduled_on: string
  status: string
}

interface ScheduleEntryList {
  data: ScheduleEntryRow[]
}

test.describe.configure({ mode: 'serial' })

test.describe('シナリオ18: 付与予定の確認から一括付与まで', () => {
  test.beforeAll(() => {
    // UC-P011: 付与予定(Schedule)をローリング生成する(scenario-08と同じく、artisanを直接実行する)。
    const output = execSync('php artisan paid-leave:roll-schedules', { cwd: BACKEND_DIR, encoding: 'utf-8' })
    expect(output).toContain('Scheduleエントリ候補')
  })

  test('有給休暇画面の「付与予定を確認」から付与予定画面を開き、対象社員の行が見える', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    await page.goto('/admin/paid-leave')
    await page.getByRole('link', { name: /付与予定を確認/ }).click()

    await expect(page).toHaveURL(/\/admin\/paid-leave\/schedule$/)
    await expect(page.getByRole('heading', { name: '付与予定' })).toBeVisible()
    await expect(page.getByRole('row', { name: new RegExp(`${REGULAR_EMPLOYEE_NAME}の付与予定詳細を開く`) }).first()).toBeVisible()
  })

  test('絞り込みはURLに残り、再読込しても保たれ、0件のときは検索条件をクリアで戻せる', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto('/admin/paid-leave/schedule')

    await page.getByLabel('状態').selectOption('eligible')
    await expect(page).toHaveURL(/filter=eligible/)

    await page.getByLabel('社員名').fill(REGULAR_EMPLOYEE_NAME)
    await page.getByLabel('社員名').press('Enter')
    await expect(page).toHaveURL(/user_name=/)

    await page.reload()
    await expect(page.getByLabel('状態')).toHaveValue('eligible')
    await expect(page.getByLabel('社員名')).toHaveValue(REGULAR_EMPLOYEE_NAME)

    await page.getByLabel('社員名').fill('存在しない社員名')
    await page.getByLabel('社員名').press('Enter')
    await expect(page.getByText('条件に一致する付与予定はありません。')).toBeVisible()

    await page.getByRole('button', { name: '検索条件をクリア' }).click()
    await expect(page).toHaveURL(/\/admin\/paid-leave\/schedule$/)
  })

  test('詳細パネルで内訳を確認して再判定し、判定結果を上書きできる', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto(`/admin/paid-leave/schedule?user_name=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`)

    await page.getByRole('row', { name: new RegExp(`${REGULAR_EMPLOYEE_NAME}の付与予定詳細を開く`) }).first().click()

    await expect(page.getByText('勤怠データを確認')).toBeVisible()
    await page.getByRole('button', { name: '再判定する' }).click()
    await expect(page.getByText('再判定に失敗しました。')).toHaveCount(0)

    await page.getByLabel('上書き後の判定').selectOption('Eligible')
    await page.getByLabel('上書き理由').fill('E2Eで出勤簿の記録漏れを確認したため')
    await page.getByRole('button', { name: '判定結果を上書きする' }).click()

    // 上書き後は保存済みとして扱い、確認なしで閉じられる。一覧には「変更あり」が付く。
    await page.keyboard.press('Escape')
    await expect(page.getByText('勤怠データを確認')).toHaveCount(0)
    await expect(page.getByRole('status', { name: '変更あり' }).first()).toBeVisible()
  })

  test('付与対象の行を選び、確認ダイアログを経て一括付与できる', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto(`/admin/paid-leave/schedule?filter=eligible&user_name=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`)

    await page.getByRole('checkbox', { name: `${REGULAR_EMPLOYEE_NAME}を選択` }).first().check()
    await page.getByRole('button', { name: '一括付与する' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('付与予定を一括付与しますか?')).toBeVisible()
    await expect(dialog.getByText(/件\(合計\d+(\.\d+)?日\)を付与します。/)).toBeVisible()
    await expect(dialog.getByText(/付与予定日: /)).toBeVisible()
    await expect(dialog.getByText(/付与後は付与予定画面から取り消せません。/)).toBeVisible()

    await dialog.getByRole('button', { name: '一括付与する' }).click()

    await expect(page.getByText(/\d+件成功 \/ \d+件失敗/)).toBeVisible()
  })

  test('一部失敗のケースはAPIで確認し、行ごとの失敗理由が返る', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    const entries = await apiFetch<ScheduleEntryList>(page, '/paid-leave/schedule-entries')
    const ineligible = entries.data.find((entry) => entry.status !== 'Eligible')
    if (!ineligible) throw new Error('E2E setup: Eligible以外の付与予定が見つからない')

    const result = await apiFetch<{
      success_count: number
      failure_count: number
      results: Array<{ schedule_entry_id: string; success: boolean; message: string | null }>
    }>(page, '/paid-leave/schedule-entries/bulk-grant', {
      method: 'POST',
      body: { schedule_entry_ids: [ineligible.id] },
    })

    expect(result.failure_count).toBeGreaterThan(0)
    const failed = result.results.find((row) => row.schedule_entry_id === ineligible.id)
    expect(failed?.success).toBe(false)
    expect(failed?.message).toBeTruthy()
  })

  test('付与予定日超過の件数があれば、警告と有給休暇画面のバッジが出る', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    const overdue = await apiFetch<ScheduleEntryList>(page, '/paid-leave/schedule-entries?filter=overdue')

    await page.goto('/admin/paid-leave')
    if (overdue.data.length > 0) {
      await expect(page.getByRole('link', { name: `付与予定を確認(付与予定日超過 ${overdue.data.length}件)` })).toBeVisible()
    }

    await page.goto('/admin/paid-leave/schedule')
    const warning = page.getByText(/付与予定日を過ぎて未付与の付与予定が\d+件あります。/)
    if (overdue.data.length > 0) {
      await expect(warning).toBeVisible()
      await page.getByRole('alert').getByRole('button', { name: '表示する' }).click()
      await expect(page).toHaveURL(/filter=overdue/)
    } else {
      await expect(warning).toHaveCount(0)
    }
  })
})
