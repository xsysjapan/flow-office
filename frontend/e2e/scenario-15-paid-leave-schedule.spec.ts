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
 * 区分・日数の個別修正(§18の手順8)は詳細パネルの「4. 区分・日数を修正」で確認する。
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
  category: string
  status: string
  is_manually_overridden: boolean
}

interface ScheduleEntryList {
  data: ScheduleEntryRow[]
}

test.describe.configure({ mode: 'serial' })

test.describe('シナリオ18: 付与予定の確認から一括付与まで', () => {
  test.beforeAll(() => {
    // UC-P011: 付与予定(Schedule)を1年先まで再計算する(scenario-08と同じく、artisanを直接実行する)。
    const output = execSync('php artisan paid-leave:roll-schedules', { cwd: BACKEND_DIR, encoding: 'utf-8' })
    expect(output).toContain('付与予定を再計算しました')
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
    await expect(page).toHaveURL(/status=eligible/)

    await page.getByLabel('社員名').fill(REGULAR_EMPLOYEE_NAME)
    await page.getByLabel('社員名').press('Enter')
    await expect(page).toHaveURL(/q=/)

    await page.reload()
    await expect(page.getByLabel('状態')).toHaveValue('eligible')
    await expect(page.getByLabel('社員名')).toHaveValue(REGULAR_EMPLOYEE_NAME)

    await page.getByLabel('社員名').fill('存在しない社員名')
    await page.getByLabel('社員名').press('Enter')
    await expect(page.getByText('条件に一致する付与予定はありません。')).toBeVisible()

    // フィルターバー末尾と0件の空状態に同名ボタンがあるため、空状態のボタン(後ろに描画される方)を押す。
    await page.getByRole('button', { name: '検索条件をクリア' }).last().click()
    await expect(page).toHaveURL(/\/admin\/paid-leave\/schedule$/)
  })

  test('詳細パネルで内訳を確認して再判定し、判定結果を上書きできる', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto(`/admin/paid-leave/schedule?q=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`)

    const row = page.getByRole('row', { name: new RegExp(`${REGULAR_EMPLOYEE_NAME}の付与予定詳細を開く`) }).first()
    await row.click()

    const sheet = page.getByRole('dialog')
    await expect(sheet.getByText('勤怠データを確認')).toBeVisible()

    // 再判定・上書きはAPIの完了を待ってから次の操作に進む(完了前に閉じると未保存扱いで確認ダイアログが出る)。
    await Promise.all([
      page.waitForResponse(
        (response) => /\/paid-leave\/schedule-entries\/[^/]+\/reassess$/.test(response.url()) && response.request().method() === 'POST' && response.ok(),
      ),
      sheet.getByRole('button', { name: '再判定する' }).click(),
    ])
    await expect(sheet.getByText('再判定に失敗しました。')).toHaveCount(0)

    await sheet.getByLabel('上書き後の判定').selectOption('Eligible')
    await sheet.getByLabel('上書き理由').fill('E2Eで出勤簿の記録漏れを確認したため')
    await Promise.all([
      page.waitForResponse(
        (response) => /\/paid-leave\/schedule-entries\/[^/]+\/override$/.test(response.url()) && response.request().method() === 'POST' && response.ok(),
      ),
      sheet.getByRole('button', { name: '判定結果を上書きする' }).click(),
    ])
    // 上書き後はパネル見出しの判定状態が「付与対象」に変わり、保存済みとして扱われて確認なしで閉じられる。
    // (「自動判定」欄にも同じ語が出得るため、見出しのバッジ=先頭の一致を見る)
    await expect(sheet.getByText('付与対象', { exact: true }).first()).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(sheet.getByText('勤怠データを確認')).toHaveCount(0)

    // 判定結果の上書きは「変更あり」(is_manually_overridden)を立てない。一覧の該当行の判定状態が「付与対象」になる。
    await expect(row.getByText('付与対象', { exact: true })).toBeVisible()
  })

  // 直前の「判定結果を上書きできる」テストで対象社員の先頭行をEligibleにしていることに依存する(serial実行)。
  test('付与対象の行を選び、確認ダイアログを経て一括付与できる', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto(`/admin/paid-leave/schedule?status=eligible&q=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`)

    await page.getByRole('checkbox', { name: `${REGULAR_EMPLOYEE_NAME}を選択` }).first().check()
    await page.getByRole('button', { name: '一括付与する' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('付与予定を一括付与しますか?')).toBeVisible()
    await expect(dialog.getByText(/件\(合計\d+(\.\d+)?日\)を付与します。/)).toBeVisible()
    await expect(dialog.getByText(/付与予定日: /)).toBeVisible()
    await expect(dialog.getByText(/付与後は付与予定画面から取り消せません。/)).toBeVisible()

    await dialog.getByRole('button', { name: /^\d+件を付与する$/ }).click()

    await expect(page.getByText(/\d+件の有給を付与しました/)).toBeVisible()
  })

  // 直前の一括付与テストで対象社員の行が付与済み(Granted)になっているため、対象社員を限定せず探す。
  // 一括付与テストの対象社員(REGULAR_EMPLOYEE_NAME)以外を優先する。区分だけを変えるので判定状態は変わらない。
  test('詳細パネルの「4. 区分・日数を修正」で区分を変えて保存すると「変更あり」になり、変更ありの絞り込みに出る', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    const entries = await apiFetch<ScheduleEntryList>(page, '/paid-leave/schedule-entries')
    const editable = entries.data.filter(
      (entry) => entry.user_name !== null && entry.status !== 'Granted' && entry.status !== 'Cancelled' && !entry.is_manually_overridden,
    )
    const target =
      editable.find((entry) => entry.user_name === SCENARIO_USERS.monthlyEmployee) ??
      editable.find((entry) => entry.user_name !== REGULAR_EMPLOYEE_NAME) ??
      editable[0]
    if (!target?.user_name) throw new Error('E2E setup: 修正できる付与予定が見つからない')
    const targetUserName = target.user_name
    const nextCategory = target.category === 'normal' ? 'proportional' : 'normal'

    await page.goto(`/admin/paid-leave/schedule?q=${encodeURIComponent(targetUserName)}`)
    const row = page
      .getByRole('row', { name: new RegExp(`${targetUserName}の付与予定詳細を開く`) })
      .filter({ hasText: target.scheduled_on })
      .first()
    await row.click()

    const sheet = page.getByRole('dialog')
    await expect(sheet.getByText('勤怠データを確認')).toBeVisible()
    await expect(sheet.getByRole('heading', { name: /区分・日数を修正/ })).toBeVisible()

    // 修正理由が空のままでは保存できない(APIは呼ばれず、理由の入力を促す)。
    await sheet.getByLabel('区分', { exact: true }).selectOption(nextCategory)
    await sheet.getByRole('button', { name: '区分・日数を修正する' }).click()
    await expect(sheet.getByText('修正理由を入力してください。')).toBeVisible()

    await sheet.getByLabel('修正理由', { exact: true }).fill('E2Eで区分の誤りを修正')
    // 修正は保存完了(PATCHの2xx)を待ってから次の操作に進む。完了前に閉じると未保存扱いで確認ダイアログが出る。
    await Promise.all([
      page.waitForResponse(
        (response) => /\/paid-leave\/schedule-entries\/[^/?]+$/.test(response.url()) && response.request().method() === 'PATCH' && response.ok(),
      ),
      sheet.getByRole('button', { name: '区分・日数を修正する' }).click(),
    ])
    await expect(sheet.getByText('修正理由: E2Eで区分の誤りを修正')).toBeVisible()

    // 保存済みとして扱われるので、確認なしで閉じられる。
    await page.keyboard.press('Escape')
    await expect(sheet.getByText('勤怠データを確認')).toHaveCount(0)

    await expect(row.getByText('変更あり')).toBeVisible()

    await page.getByLabel('状態').selectOption('changed')
    await expect(page).toHaveURL(/status=changed/)
    await expect(
      page
        .getByRole('row', { name: new RegExp(`${targetUserName}の付与予定詳細を開く`) })
        .filter({ hasText: target.scheduled_on })
        .first(),
    ).toBeVisible()
  })

  test('付与済みの行の詳細パネルには「4. 区分・日数を修正」が出ない', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    const entries = await apiFetch<ScheduleEntryList>(
      page,
      `/paid-leave/schedule-entries?user_name=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`,
    )
    const granted = entries.data.find((entry) => entry.status === 'Granted')
    if (!granted) throw new Error('E2E setup: 付与済みの付与予定が見つからない')

    await page.goto(`/admin/paid-leave/schedule?q=${encodeURIComponent(REGULAR_EMPLOYEE_NAME)}`)
    await page
      .getByRole('row', { name: new RegExp(`${REGULAR_EMPLOYEE_NAME}の付与予定詳細を開く`) })
      .filter({ hasText: granted.scheduled_on })
      .first()
      .click()

    const sheet = page.getByRole('dialog')
    await expect(sheet.getByText('勤怠データを確認')).toBeVisible()
    await expect(sheet.getByRole('heading', { name: /区分・日数を修正/ })).toHaveCount(0)
    await expect(sheet.getByRole('button', { name: '区分・日数を修正する' })).toHaveCount(0)
  })

  test('付与できない行が含まれると、APIは422で全体を拒否し、何も付与しない', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)

    const entries = await apiFetch<ScheduleEntryList>(page, '/paid-leave/schedule-entries')
    const ineligible = entries.data.find((entry) => entry.status !== 'Eligible')
    if (!ineligible) throw new Error('E2E setup: Eligible以外の付与予定が見つからない')

    // 全部か無しか: 1件でも付与できないと422(code: bulk_operation_rejected)。失敗した行と理由の一覧は
    // backendのテストで確かめる(apiFetchはメッセージだけを例外にする)。
    await expect(
      apiFetch(page, '/paid-leave/schedule-entries/bulk-grant', {
        method: 'POST',
        body: { schedule_entry_ids: [ineligible.id] },
      }),
    ).rejects.toThrow(/failed \(422\): 選んだ対象のうち、処理できないものがあります。/)

    const after = await apiFetch<ScheduleEntryList>(page, '/paid-leave/schedule-entries')
    expect(after.data.find((entry) => entry.id === ineligible.id)?.status).toBe(ineligible.status)
  })

  // 付与予定画面の「自動判定」カードの「今すぐ判定する」。受付(202)のあと、毎分のスケジュールの経路
  // (`--pending`)を直接実行して判定を完了させ、カードに前回の結果が出るまで待つ。
  test('「今すぐ判定する」で判定を受け付け、判定が終わると前回の結果が表示される', async ({ page }) => {
    await loginAs(page, SCENARIO_USERS.hrStaff)
    await page.goto('/admin/paid-leave/schedule')

    const card = page.getByRole('region', { name: '自動判定' })
    await expect(card.getByText(/^次回の自動判定: /)).toBeVisible()
    await card.getByRole('button', { name: '今すぐ判定する' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('付与予定日を過ぎた『予定』の行を、今すぐ判定します。')).toBeVisible()
    await Promise.all([
      page.waitForResponse(
        (response) => /\/paid-leave\/schedule-assessment-runs$/.test(response.url()) && response.request().method() === 'POST' && response.status() === 202,
      ),
      dialog.getByRole('button', { name: '判定する', exact: true }).click(),
    ])

    // 受付のあと、毎分のスケジュールと同じ経路で待っている実行を1件動かす(キューは使わない)。
    execSync('php artisan paid-leave:assess-schedules --pending', { cwd: BACKEND_DIR, encoding: 'utf-8' })

    // 実行中の間はカードが5秒ごとに状態を取り直すので、完了の表示を待つ。
    await expect(card.getByText(/^前回: .+ 完了/)).toBeVisible({ timeout: 15000 })
    await expect(card.getByText('判定を実行中です')).toHaveCount(0)
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
      await expect(page).toHaveURL(/status=overdue/)
    } else {
      await expect(warning).toHaveCount(0)
    }
  })
})
