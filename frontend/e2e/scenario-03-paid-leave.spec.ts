import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md シナリオ3(勤怠管理中の有給消化)。
 * 終日有給・半休それぞれの申請〜承認〜勤怠反映までを確認する。
 */
test('有給残数画面が表示できる', async ({ page }) => {
  await loginAs(page, SCENARIO_USERS.punchEmployee)
  await page.goto('/paid-leave')
  await expect(page.getByRole('heading', { name: '自分の有給', exact: true })).toBeVisible()
})

test.skip('終日有給を申請〜承認し、勤怠日に反映される (TODO)', async () => {
  // 1. /paid-leave から新規申請 (leave_type=full, 承認者=渡辺直樹)
  // 2. 渡辺直樹でログインし直し /paid-leave/to-approve から承認
  // 3. 本人で /attendance/week を開き、対象日が有給扱い(未退勤警告が出ない)ことを確認
})

test.skip('半休を申請〜承認し、勤怠日に反映される (TODO)', async () => {
  // leave_type=am_half / pm_half のケース。手順は終日有給と同様。
})
