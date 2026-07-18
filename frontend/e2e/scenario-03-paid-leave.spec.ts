import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { grantAdditionalPaidLeave } from './support/api'
import { pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md シナリオ3(勤怠管理中の有給消化)。
 * 終日有給・半休それぞれの申請〜承認〜勤怠反映までを確認する。
 *
 * 申請者と承認者は別人としてログインする必要があるため、1つの browser から
 * newContext() で独立したセッション(Cookie/localStorageが別)を作り、
 * それぞれでログインする。
 */
test('有給残数画面が表示できる', async ({ page }) => {
  await loginAs(page, SCENARIO_USERS.punchEmployee)
  await page.goto('/paid-leave')
  await expect(page.getByRole('heading', { name: '自分の有給', exact: true })).toBeVisible()
})

/**
 * ScenarioSeederのシフト予定期間(前後1か月)に収まる平日をランダムに選ぶ。
 * 当月(今月)は他のシナリオ(scenario-01/04/06、scenario-99 §5-3/§5-8等が使う
 * `submitApproveAndCloseCurrentMonth`)が承認・締めまで進めてしまうことがあり、
 * 承認済みの月次に含まれる日は日次実績を変更できなくなる(UC-A011のガード)。有給申請の
 * 承認もこのガードの対象のため、当月と衝突しない翌月から選ぶ(ScenarioSeederのシフト予定は
 * 前月〜翌月まであるため、翌月であれば確実にシフト予定が存在する)。
 */
function randomWorkingDate(): string {
  const now = new Date()
  const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1)
  const daysInNextMonth = new Date(nextMonthStart.getFullYear(), nextMonthStart.getMonth() + 1, 0).getDate()
  // 週末補正で翌々月にはみ出さないよう、月末3日は候補から外す。
  const day = 1 + Math.floor(Math.random() * (daysInNextMonth - 3))
  const date = new Date(nextMonthStart.getFullYear(), nextMonthStart.getMonth(), day)
  while (date.getDay() === 0 || date.getDay() === 6) {
    date.setDate(date.getDate() + 1)
  }
  return date.toISOString().slice(0, 10)
}

/** frontend/src/utils/weekDates.ts の mondayOf と同じ規則で、月曜始まりの週数の差を求める。 */
function mondayOf(date: Date): Date {
  const d = new Date(date)
  const dow = d.getDay()
  d.setDate(d.getDate() + (dow === 0 ? -6 : 1 - dow))
  d.setHours(0, 0, 0, 0)
  return d
}

function weeksBetweenMondays(from: Date, to: Date): number {
  const diffMs = mondayOf(to).getTime() - mondayOf(from).getTime()
  return Math.round(diffMs / (7 * 24 * 60 * 60 * 1000))
}

/**
 * 有給を申請する。同じ対象日への重複申請はドメインルールで拒否されるため
 * (「この日は既に有給を申請済みです。」)、このE2Eテストを何度も実行して過去の
 * 実行結果と衝突した場合は、別の日を選んで再試行する。ページは /paid-leave を
 * 開いた状態で呼び出すこと。
 */
async function submitPaidLeaveRequest(
  page: Page,
  options: { leaveTypeLabel: string; approverName: string; approverEmail: string },
): Promise<string> {
  for (let attempt = 0; attempt < 5; attempt++) {
    const targetDate = randomWorkingDate()

    try {
      await page.locator('#paid-leave-target-date').fill(targetDate, { timeout: 5000 })
      await page.getByLabel('取得単位').selectOption({ label: options.leaveTypeLabel }, { timeout: 5000 })
      await pickUser(page, '承認者', options.approverName, options.approverEmail, { timeout: 5000 })
      await page.getByRole('button', { name: '申請する' }).click({ timeout: 5000 })

      const duplicateError = page.getByText('この日は既に有給を申請済みです。')
      const submittedRow = page.locator('li', { hasText: targetDate }).getByRole('status', { name: '申請中' })

      const result = await Promise.race([
        duplicateError
          .waitFor({ state: 'visible', timeout: 5000 })
          .then(() => 'duplicate' as const)
          .catch(() => null),
        submittedRow
          .waitFor({ state: 'visible', timeout: 5000 })
          .then(() => 'submitted' as const)
          .catch(() => null),
      ])

      if (result === 'submitted') return targetDate
    } catch {
      // このtargetDateでの入力・送信が何らかの理由(UserPickerの候補表示待ちタイムアウト等)で
      // 失敗した場合も、日を変えて再試行する。
    }
  }

  throw new Error('有給申請に5回試行しても成功しなかった(重複日との衝突が続いた可能性)')
}

test('終日有給を申請〜承認し、勤怠日に反映される', async ({ browser }) => {
  test.setTimeout(60000) // 対象日の重複衝突時に複数回リトライすることがあるため長めに取る
  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()

    // 何度もテストを実行すると付与済みの有給を使い切るため、申請の前に管理者として
    // 追加付与しておく。専用のコンテキストで行い、申請者のセッションには影響させない。
    const adminContext = await browser.newContext()
    const adminPage = await adminContext.newPage()
    await loginAs(adminPage, SCENARIO_USERS.admin)
    await grantAdditionalPaidLeave(adminPage, 'kenta.takahashi@example.com', 5)
    await adminContext.close()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/paid-leave')
    const targetDate = await submitPaidLeaveRequest(applicantPage, {
      leaveTypeLabel: '全休',
      approverName: SCENARIO_USERS.approver,
      approverEmail: 'naoki.watanabe@example.com',
    })

    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/paid-leave/to-approve')
    const approvalRow = approverPage.locator('li', { hasText: targetDate })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('button', { name: '承認する' }).click()
    await expect(approvalRow).toHaveCount(0)

    // 勤怠週次画面で対象日が有給扱いになり、退勤していないのに「打刻漏れ」警告が
    // 出ないことを確認する(UC-P004: attendance_days.work_type=paid_leave_full,
    // status=clocked_out に反映される)。週次画面は当週始まりのため、対象日が含まれる
    // 週まで「次週」を押して移動する(週数は当週との差から算出する)。
    const weeksAhead = weeksBetweenMondays(new Date(), new Date(`${targetDate}T00:00:00`))
    await applicantPage.goto('/attendance/week')
    for (let i = 0; i < weeksAhead; i++) {
      await applicantPage.getByRole('button', { name: '次週' }).click()
    }
    const weekRow = applicantPage.getByRole('listitem').filter({ hasText: targetDate })
    await expect(weekRow).toBeVisible()
    await expect(weekRow.getByRole('status', { name: '打刻漏れ' })).toHaveCount(0)

    // 有給残数が減っていることを確認する。
    await applicantPage.goto('/paid-leave')
    await expect(applicantPage.getByText('使用日数')).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
  }
})

test('半休を申請〜承認し、勤怠日に反映される', async ({ browser }) => {
  test.setTimeout(60000) // 対象日の重複衝突時に複数回リトライすることがあるため長めに取る
  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()

    const adminContext = await browser.newContext()
    const adminPage = await adminContext.newPage()
    await loginAs(adminPage, SCENARIO_USERS.admin)
    await grantAdditionalPaidLeave(adminPage, 'kenta.takahashi@example.com', 5)
    await adminContext.close()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/paid-leave')
    const targetDate = await submitPaidLeaveRequest(applicantPage, {
      leaveTypeLabel: '午前半休',
      approverName: SCENARIO_USERS.approver,
      approverEmail: 'naoki.watanabe@example.com',
    })

    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/paid-leave/to-approve')
    const approvalRow = approverPage.locator('li', { hasText: targetDate })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('button', { name: '承認する' }).click()
    await expect(approvalRow).toHaveCount(0)

    await applicantPage.reload()
    await expect(
      applicantPage.locator('li', { hasText: targetDate }).getByRole('status', { name: '承認済み' }),
    ).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
  }
})
