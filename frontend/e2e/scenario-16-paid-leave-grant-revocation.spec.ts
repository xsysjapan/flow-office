import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  approvePaidLeaveRequest,
  createPaidLeaveGrant,
  fetchPaidLeaveGrantsForUser,
  fetchUserIdByEmail,
  requestPaidLeave,
} from './support/api'
import { pickUser } from './support/ui'

/**
 * 有給付与の取消(UC-P008)。報告されたケースを画面操作で再現する:
 * 新しい付与(B)を取り消したあと、消化済みの古い付与(A)を取り消す。
 *
 * 対象社員は小林 誠(ScenarioSeederで有給を付与されておらず、他の有給系e2eで使われていない)。
 * 付与A(今日・10日)・付与B(翌日・10日)を作り、Aに1日分の消化を充当してから画面で取り消す。
 */

const APPLICANT_EMAIL = 'makoto.kobayashi@example.com'
const APPROVER_EMAIL = 'naoki.watanabe@example.com'

function addDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

/** 付与日の1年後(2年後)の前日。 */
function expiresOnAfterYears(grantedOn: string, years: number): string {
  const d = new Date(`${grantedOn}T00:00:00Z`)
  d.setUTCFullYear(d.getUTCFullYear() + years)
  d.setUTCDate(d.getUTCDate() - 1)
  return d.toISOString().slice(0, 10)
}

/** scenario-03と同じ考え方で、翌月の平日(シフト予定が確実にある月)をランダムに選ぶ。 */
function randomWorkingDate(): string {
  const now = new Date()
  const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1)
  const daysInNextMonth = new Date(nextMonthStart.getFullYear(), nextMonthStart.getMonth() + 1, 0).getDate()
  const day = 1 + Math.floor(Math.random() * (daysInNextMonth - 3))
  const date = new Date(nextMonthStart.getFullYear(), nextMonthStart.getMonth(), day)
  while (date.getDay() === 0 || date.getDay() === 6) {
    date.setDate(date.getDate() + 1)
  }
  return date.toISOString().slice(0, 10)
}

/** 重複日などで失敗したら別の日で再試行して、終日有給を申請する。 */
async function requestFullDayLeave(page: Page, approverUserId: string): Promise<string> {
  let lastError: unknown
  for (let attempt = 0; attempt < 20; attempt++) {
    try {
      const request = await requestPaidLeave(page, {
        targetDate: randomWorkingDate(),
        leaveType: 'full',
        approverUserId,
        reason: 'E2Eテスト用有給申請(scenario-16)',
      })
      return request.id
    } catch (error) {
      lastError = error
    }
  }
  throw new Error(`有給申請に20回試行しても成功しなかった: ${String(lastError)}`)
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

    // 前提: 付与は新しい付与日にしか作れない。既存の付与(取消済みを除く)があれば、その最新の付与日より後にずらす。
    const existingGrants = await fetchPaidLeaveGrantsForUser(adminPage, applicantUserId)
    const latestExisting = existingGrants
      .filter((grant) => (grant as { status?: string }).status !== 'revoked')
      .map((grant) => grant.granted_on.slice(0, 10))
      .sort()
      .at(-1)
    const today = new Date().toISOString().slice(0, 10)
    const grantedOnA = latestExisting !== undefined && latestExisting >= today ? addDays(latestExisting, 1) : today
    const grantedOnB = addDays(grantedOnA, 1)

    const grantA = await createPaidLeaveGrant(adminPage, {
      userId: applicantUserId,
      grantedOn: grantedOnA,
      expiresOn: expiresOnAfterYears(grantedOnA, 1),
      days: 10,
      reason: 'E2Eテスト用付与A(scenario-16)',
    })
    const grantB = await createPaidLeaveGrant(adminPage, {
      userId: applicantUserId,
      grantedOn: grantedOnB,
      expiresOn: expiresOnAfterYears(grantedOnB, 2),
      days: 10,
      reason: 'E2Eテスト用付与B(scenario-16)',
    })

    // 終日有給を申請〜承認し、先に付与された付与Aに1日分が充当されることを確かめる。
    const requestId = await requestFullDayLeave(applicantPage, approverUserId)
    await approvePaidLeaveRequest(approverPage, requestId)
    const grantsAfterApproval = await fetchPaidLeaveGrantsForUser(adminPage, applicantUserId)
    expect(grantsAfterApproval.find((grant) => grant.id === grantA.id)?.used_days).toBe(1)

    // 画面: 有給付与ポリシー画面で小林 誠を個別に選ぶと「対象社員の有給付与状況」が出る。
    await adminPage.goto('/admin/paid-leave')
    await pickUser(adminPage, '付与対象', SCENARIO_USERS.accountingStaff, APPLICANT_EMAIL, { exact: true })
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
