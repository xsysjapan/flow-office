import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { recordAttendancePunch, submitAndApproveMonth } from './support/api'
import { goToAttendanceWeekContaining } from './support/ui'

/**
 * docs/testing/scenario-tests.md §5-11/§5-12(その他、用意しておくべきシナリオ)に対応する。
 *
 * 対象日は「今日」から数か月先の日付を使う。当月は他のシナリオ
 * (§5-3/§5-8, `submitApproveAndCloseCurrentMonth`)が締めまで進めてしまうことがあり、
 * 同じ月を使うと「承認前は削除できる」という前提が崩れるため。
 */
function nthOfMonthsAhead(monthsAhead: number, dayOfMonth: number): string {
  const now = new Date()
  const date = new Date(now.getFullYear(), now.getMonth() + monthsAhead, dayOfMonth)
  return date.toISOString().slice(0, 10)
}

/**
 * 週次画面の日次勤怠行(`<li class="py-3">`)だけを指す。打刻ログ欄の各行も同じ
 * `listitem`ロールかつ対象日付の文字列を含むため、日付の直後に曜日の丸括弧が続く
 * 見出し("2026-11-20(金)")で絞り込んで区別する。
 */
function dayRow(page: Page, date: string) {
  return page.getByRole('listitem').filter({ hasText: `${date}(` })
}

test('§5-11: 打刻ログの訂正・削除', async ({ page }) => {
  test.setTimeout(30000)
  const targetDate = nthOfMonthsAhead(4, 20)

  await loginAs(page, SCENARIO_USERS.punchEmployee)

  // 出勤時刻を09:30と打刻したが、本来は09:00だった(入力ミス)。
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_in', punchedAt: `${targetDate}T09:30:00+09:00` })
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_out', punchedAt: `${targetDate}T18:00:00+09:00` })

  await goToAttendanceWeekContaining(page, targetDate)
  const targetRow = dayRow(page, targetDate)
  await expect(targetRow).toContainText('09:30')

  // 打刻ログを開き、誤った出勤打刻を訂正する(UC-A013)。
  await targetRow.getByRole('button', { name: '打刻ログを表示' }).click()
  const clockInPunchRow = targetRow.locator('li').filter({ hasText: '出勤' }).filter({ hasText: '有効' })
  await expect(clockInPunchRow).toHaveCount(1)
  await clockInPunchRow.getByRole('button', { name: '訂正' }).click()

  const datetimeInput = clockInPunchRow.getByLabel('訂正後の日時')
  await datetimeInput.fill(`${targetDate}T09:00`)
  await clockInPunchRow.getByLabel('訂正理由').fill('打刻時刻の入力ミス')
  await clockInPunchRow.getByRole('button', { name: '訂正を保存' }).click()

  // 日次勤怠は訂正後の時刻(09:00)に組み立て直される。元の打刻(09:30)は削除されず
  // 「訂正済み」として理由付きで参照できる(打刻ログは追記のみのため)。
  await expect(targetRow).toContainText('09:00')
  const correctedOriginalRow = targetRow.locator('li').filter({ hasText: '訂正済み' })
  await expect(correctedOriginalRow).toContainText('09:30')
  await expect(correctedOriginalRow).toContainText('打刻時刻の入力ミス')

  // 重複打刻(誤って2回目の出勤打刻をしてしまったケース)を記録し、削除する(UC-A014)。
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_in', punchedAt: `${targetDate}T09:45:00+09:00` })

  // usePunches は打刻ログ欄を開閉すると再取得されるため、閉じて開き直して最新化する。
  await targetRow.getByRole('button', { name: '打刻ログを閉じる' }).click()
  await targetRow.getByRole('button', { name: '打刻ログを表示' }).click()

  const duplicatePunchRow = targetRow.locator('li').filter({ hasText: '09:45' })
  await duplicatePunchRow.getByRole('button', { name: '削除' }).click()
  await duplicatePunchRow.getByLabel('削除理由').fill('二重打刻の削除')
  await duplicatePunchRow.getByRole('button', { name: '削除する' }).click()

  await expect(duplicatePunchRow.filter({ hasText: '削除済み' })).toContainText('二重打刻の削除')
  // 削除後も日次勤怠(09:00〜18:00)は正しく組み立てられたままになる。
  await expect(targetRow).toContainText('09:00')
})

test('§5-12: 日次勤怠の削除(承認前は削除できるが、承認後は削除・変更できない)', async ({ browser }) => {
  test.setTimeout(60000)
  // 削除後に同じ日へ打刻をやり直すと、削除前の打刻ログが残ったままになり
  // (削除APIは打刻ログではなく日次勤怠だけを消す)矛盾扱いになってしまう。そのため
  // 「削除できることの確認」と「削除できないことの確認」は同じ月内の別々の日で行う。
  const deletableDate = nthOfMonthsAhead(2, 10)
  const protectedDate = nthOfMonthsAhead(2, 20)
  const targetYearMonth = deletableDate.slice(0, 7)

  const employeeContext = await browser.newContext()
  const approverContext = await browser.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()

    await loginAs(employeePage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)

    // 承認前(未提出)は削除できる(UC-A015)。
    await recordAttendancePunch(employeePage, { workDate: deletableDate, punchType: 'clock_in', punchedAt: `${deletableDate}T09:00:00+09:00` })
    await recordAttendancePunch(employeePage, { workDate: deletableDate, punchType: 'clock_out', punchedAt: `${deletableDate}T18:00:00+09:00` })

    await goToAttendanceWeekContaining(employeePage, deletableDate)
    const deletableRow = dayRow(employeePage, deletableDate)
    await expect(deletableRow).toContainText('退勤済み')

    await deletableRow.getByRole('button', { name: '削除' }).click()
    await employeePage.getByLabel('削除理由').fill('二重入力の削除(E2E)')
    await employeePage.getByRole('button', { name: '削除する' }).click()
    await expect(deletableRow).toContainText('未入力')

    // 別日の実績を入力し、月次を提出〜承認まで進める。
    await recordAttendancePunch(employeePage, { workDate: protectedDate, punchType: 'clock_in', punchedAt: `${protectedDate}T09:00:00+09:00` })
    await recordAttendancePunch(employeePage, { workDate: protectedDate, punchType: 'clock_out', punchedAt: `${protectedDate}T18:00:00+09:00` })
    await submitAndApproveMonth(employeePage, approverPage, targetYearMonth)

    // 締め(locked_at)はまだ行われていないが、月次が承認済みになった時点で削除・編集は
    // どちらもできなくなる(既存実装の抜け穴の修正確認。UC-A005/UC-A015)。
    await goToAttendanceWeekContaining(employeePage, protectedDate)
    const protectedRow = dayRow(employeePage, protectedDate)
    await expect(protectedRow).toContainText('退勤済み')

    await protectedRow.getByRole('button', { name: '削除' }).click()
    await employeePage.getByLabel('削除理由').fill('承認後の削除テスト(E2E)')
    await employeePage.getByRole('button', { name: '削除する' }).click()
    await expect(
      employeePage.getByRole('alert').filter({ hasText: '承認済みの月次勤怠に含まれる日次勤怠は修正申請から変更してください。' }),
    ).toBeVisible()

    await employeePage.keyboard.press('Escape')
    await protectedRow.getByRole('button', { name: '編集' }).click()
    await protectedRow.getByLabel('修正理由(必須)').fill('承認後編集の拒否確認(E2E)')
    await protectedRow.getByRole('button', { name: '保存する' }).click()
    await expect(
      protectedRow.getByRole('alert').filter({ hasText: '承認済みの月次勤怠に含まれる日次勤怠は修正申請から変更してください。' }),
    ).toBeVisible()
  } finally {
    await employeeContext.close()
    await approverContext.close()
  }
})
