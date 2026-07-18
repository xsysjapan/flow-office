import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { closeMonth, createAttendanceDay, fetchMonthStatus, fetchOwnUserId, submitAndApproveMonth } from './support/api'

/**
 * docs/testing/scenario-tests.md シナリオ2(月次入力ユーザーの1か月勤怠)。
 *
 * 日次実績の新規作成(UC-A016、`POST /attendance/days`)→週次画面での確認→月次提出→
 * 承認→締め、という流れを検証する。日次実績の新規作成画面はまだ無いため、入力はAPIを
 * 直接叩く(`WeekAttendancePage`の編集フォームは既存行の編集のみに対応しており、行が
 * 無い日の新規作成にはまだ対応していない)。
 */
function mondayOf(date: Date): Date {
  const d = new Date(date)
  const dow = d.getDay()
  d.setDate(d.getDate() + (dow === 0 ? -6 : 1 - dow))
  d.setHours(0, 0, 0, 0)
  return d
}

function formatDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

test('月次入力ユーザーが打刻せず日次実績を新規作成し、月次提出〜承認〜締めまで進む', async ({ browser }) => {
  test.setTimeout(60000)

  const employeeContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(employeePage, SCENARIO_USERS.monthlyEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    const userId = await fetchOwnUserId(employeePage)

    // ScenarioSeederは前月〜翌月分の勤務予定も生成済みのため、既に終わった月として
    // 提出〜承認〜締めまで進められるよう前月を対象にする。
    const now = new Date()
    const targetMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1)
    const yearMonth = `${targetMonthDate.getFullYear()}-${String(targetMonthDate.getMonth() + 1).padStart(2, '0')}`

    // 対象月内の平日を1日選ぶ(1日が週末なら平日になるまで進める)。
    const targetDate = new Date(targetMonthDate)
    while (targetDate.getDay() === 0 || targetDate.getDay() === 6) {
      targetDate.setDate(targetDate.getDate() + 1)
    }
    const workDate = formatDate(targetDate)

    // 既に本テストを実行済みの場合に備え、月が編集可能な状態(未提出/差戻し)であることを
    // 前提にする(締め済みなら再作成できないため、この行の作成は初回のみ成功する想定)。
    const alreadyClosed = (await fetchMonthStatus(employeePage, yearMonth)) === 'closed'
    if (!alreadyClosed) {
      await createAttendanceDay(employeePage, {
        userId,
        workDate,
        actualStartAt: `${workDate}T09:00:00+09:00`,
        actualEndAt: `${workDate}T18:00:00+09:00`,
        breaks: [{ start: `${workDate}T12:00:00+09:00`, end: `${workDate}T13:00:00+09:00` }],
        reason: '月次入力ユーザーの日次実績新規作成(E2E, UC-A016)',
      }).catch((error: Error) => {
        // 2回目以降の実行時、既に作成済みなら「既に存在します」エラーになるため許容する。
        if (!error.message.includes('既に存在します')) throw error
      })
    }

    // 週次画面で、打刻を一切していないその日が「未入力」ではなく労働時間付きで表示される
    // ことを確認する(対象日が属する週まで「前週」ボタンで移動する)。
    const targetMonday = mondayOf(targetDate)
    const currentMonday = mondayOf(now)
    const weeksBack = Math.round((currentMonday.getTime() - targetMonday.getTime()) / (7 * 24 * 60 * 60 * 1000))

    await employeePage.goto('/attendance/week')
    for (let i = 0; i < weeksBack; i++) {
      await employeePage.getByRole('button', { name: '前週' }).click()
    }

    const targetRow = employeePage.getByRole('listitem').filter({ hasText: workDate })
    await expect(targetRow).toBeVisible()
    await expect(targetRow.getByText('労働時間')).toBeVisible()
    // 労働時間の表示は`Duration`コンポーネント導入により「480分」ではなく「8時間」(8時間0分)
    // という表記になった(2026-07時点でのUI変更)。
    await expect(targetRow.getByText('8時間')).toBeVisible()
    await expect(targetRow.getByText('未入力')).toHaveCount(0)

    // 月次提出→承認→締めまで進める(打刻ユーザーと同じ仕組みに乗っていることの確認)。
    await submitAndApproveMonth(employeePage, approverPage, yearMonth)
    await closeMonth(adminPage, employeePage, yearMonth)

    expect(await fetchMonthStatus(employeePage, yearMonth)).toBe('closed')
  } finally {
    await employeeContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})
