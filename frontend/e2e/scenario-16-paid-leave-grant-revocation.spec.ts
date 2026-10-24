import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  approvePaidLeaveRequest,
  createPaidLeaveGrant,
  createWorkStyleViaApi,
  fetchPaidLeaveGrantsForUser,
  fetchUserIdByEmail,
  generateShiftAssignments,
  requestPaidLeave,
} from './support/api'

/**
 * 有給付与の取消(UC-P008)。報告されたケースを画面操作で再現する:
 * 新しい付与(B)を取り消したあと、消化済みの古い付与(A)を取り消す。
 *
 * 対象社員は小林 誠(ScenarioSeederで有給・勤務予定を持たない)。ただしscenario-09 §5-14が
 * 9000〜9399年のランダムな年に、この社員の付与・勤務予定・消化を作る。付与は既存の付与日より
 * 後にしか作れないため、本シナリオは専用の年の範囲9500〜9899年からランダムに年を選び、
 * scenario-09より後の付与日になるようにする(他シナリオの年の範囲とも重ならない)。
 * 勤務予定が無いと有給を申請できないため、固定時間制の勤務形態を作って{year}-06の勤務予定を作る。
 * 付与A({year}-04-01・10日)・付与B({year}-04-02・10日)を作り、{year}-06の最初の平日の
 * 終日有給を申請・承認してAに1日分の消化を充当してから画面で取り消す。
 * 画面の一覧にはscenario-09の付与も出ることがあるため、行は付与日の完全一致で特定する。
 */

const APPLICANT_EMAIL = 'makoto.kobayashi@example.com'
const APPROVER_EMAIL = 'naoki.watanabe@example.com'

/** 指定した年月内の平日("YYYY-MM-DD")を1日から順に列挙する(scenario-09と同じ)。 */
function weekdaysOfMonth(year: number, month: number): string[] {
  const dates: string[] = []
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate()
  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = new Date(Date.UTC(year, month - 1, day))
    const dow = date.getUTCDay()
    if (dow !== 0 && dow !== 6) {
      dates.push(`${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`)
    }
  }
  return dates
}

test('最新の付与を取り消したあと、消化済みの古い付与も画面から取り消せる', async ({ browser }) => {
  test.setTimeout(180000)
  const adminContext = await browser.newContext()
  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()

  try {
    const adminPage = await adminContext.newPage()
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()

    await loginAs(adminPage, SCENARIO_USERS.admin)
    await loginAs(applicantPage, SCENARIO_USERS.accountingStaff)
    await loginAs(approverPage, SCENARIO_USERS.approver)

    const applicantUserId = await fetchUserIdByEmail(adminPage, APPLICANT_EMAIL)
    const approverUserId = await fetchUserIdByEmail(adminPage, APPROVER_EMAIL)

    // 前提: 勤務予定(固定時間制)と付与A・Bを作る。年はscenario-09(9000〜9399)より後の範囲。
    const year = 9500 + Math.floor(Math.random() * 400)
    const grantedOnA = `${year}-04-01`
    const grantedOnB = `${year}-04-02`

    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `rv16_${year}`,
      name: `E2E 有給付与取消確認${year}`,
      workTimeSystem: 'fixed',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
    })
    await generateShiftAssignments(adminPage, {
      userId: applicantUserId,
      workStyleId: workStyle.id,
      from: `${year}-06-01`,
      to: `${year}-06-30`,
    })

    const grantA = await createPaidLeaveGrant(adminPage, {
      userId: applicantUserId,
      grantedOn: grantedOnA,
      expiresOn: `${year + 1}-03-31`,
      days: 10,
      reason: 'E2Eテスト用付与A(scenario-16)',
    })
    const grantB = await createPaidLeaveGrant(adminPage, {
      userId: applicantUserId,
      grantedOn: grantedOnB,
      expiresOn: `${year + 2}-04-01`,
      days: 10,
      reason: 'E2Eテスト用付与B(scenario-16)',
    })

    // 終日有給を申請〜承認し、先に付与された付与Aに1日分が充当されることを確かめる。
    const request = await requestPaidLeave(applicantPage, {
      targetDate: weekdaysOfMonth(year, 6)[0],
      leaveType: 'full',
      approverUserId,
      reason: 'E2Eテスト用有給申請(scenario-16)',
    })
    await approvePaidLeaveRequest(approverPage, request.id)
    const grantsAfterApproval = await fetchPaidLeaveGrantsForUser(adminPage, applicantUserId)
    expect(grantsAfterApproval.find((grant) => grant.id === grantA.id)?.used_days).toBe(1)

    // 画面: 有給付与ポリシー画面で小林 誠を個別に選ぶと「対象社員の有給付与状況」が出る。
    await adminPage.goto('/admin/paid-leave')
    // 付与対象の複数選択ポップオーバーの候補は、ビューポートを広げてもPlaywrightが「画面外」と判定してクリックできない
    // (pickUserが止まる)ため、検索結果の候補にclickイベントを直接送って選ぶ。
    await adminPage.getByLabel('付与対象', { exact: true }).click()
    await adminPage.getByPlaceholder('氏名またはメールアドレスで検索').fill(SCENARIO_USERS.accountingStaff)
    await adminPage.getByRole('option', { name: `${SCENARIO_USERS.accountingStaff}(${APPLICANT_EMAIL})` }).dispatchEvent('click')
    await expect(adminPage.getByRole('listitem').filter({ hasText: `${SCENARIO_USERS.accountingStaff}(${APPLICANT_EMAIL})` })).toBeVisible()
    await adminPage.keyboard.press('Escape')
    await expect(adminPage.getByRole('heading', { name: '対象社員の有給付与状況' })).toBeVisible()

    const rowA = adminPage.locator('li', { hasText: `${grantedOnA} 〜` })
    const rowB = adminPage.locator('li', { hasText: `${grantedOnB} 〜` })

    // 1. 付与Aは最新でないので取消ボタンが無効、付与Bは押せる。
    await expect(rowA.getByRole('button', { name: '取消' })).toBeDisabled()
    await expect(rowA.getByRole('button', { name: '取消' })).toHaveAttribute('title', '最新の付与のみ取り消せます。')
    await expect(rowB.getByRole('button', { name: '取消' })).toBeEnabled()

    // 2. 付与Bを取り消す。
    await rowB.getByRole('button', { name: '取消' }).click()
    const revokeBResponse = adminPage.waitForResponse(
      (response) => response.url().endsWith(`/paid-leave/grants/${grantB.id}/revoke`) && response.request().method() === 'POST',
    )
    await adminPage.getByRole('dialog').getByRole('button', { name: '取消する' }).click()
    expect((await revokeBResponse).ok()).toBe(true)
    await expect(rowB).toContainText('残0日')
    await expect(rowB).toContainText('取消済み')

    // 3. 消化済みの付与Aでも取消ボタンが押せ、確認文に付け替えの説明が出る。
    const revokeAButton = rowA.getByRole('button', { name: '取消' })
    await expect(revokeAButton).toBeEnabled()
    await revokeAButton.click()
    const dialog = adminPage.getByRole('dialog')
    await expect(dialog.getByText('消化済みの1日分は他の付与へ付け替えます。')).toBeVisible()
    const revokeAResponse = adminPage.waitForResponse(
      (response) => response.url().endsWith(`/paid-leave/grants/${grantA.id}/revoke`) && response.request().method() === 'POST',
    )
    await dialog.getByRole('button', { name: '取消する' }).click()
    expect((await revokeAResponse).ok()).toBe(true)

    // 4. 付与Aも「残0日」「取消済み」になる。
    await expect(rowA).toContainText('残0日')
    await expect(rowA).toContainText('取消済み')
  } finally {
    await adminContext.close()
    await applicantContext.close()
    await approverContext.close()
  }
})
