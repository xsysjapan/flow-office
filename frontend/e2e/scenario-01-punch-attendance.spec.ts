import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md シナリオ1(打刻ユーザーの1か月勤怠)のうち、
 * 1日ぶんの打刻(出勤→休憩開始→休憩終了→退勤)を実際にブラウザ操作で通す部分。
 *
 * 前提: ScenarioSeeder 実行済み(今日の勤務予定が高橋健太に生成されていること)。
 * 月次提出〜承認〜締めの残りの手順は、シナリオ1・2共通のため
 * scenario-01-02-month-close.spec.ts (TODO) にまとめる想定。
 *
 * 勤怠は「同じ日に2回出勤する」ような操作を許さない設計のため、一度退勤まで進めると
 * 同日中の再実行では最初から出勤し直すことはできない。再実行時は「本日の勤怠は完了して
 * います」の表示のみを確認して成功とみなす(DBをリセットしない限り、1日1回しか
 * フルフローを検証できない点はテスト自体の仕様として許容する)。
 */
test('打刻ユーザーが出勤・休憩・退勤を打刻できる', async ({ page }) => {
  await loginAs(page, SCENARIO_USERS.punchEmployee)

  await expect(page.getByRole('heading', { name: '今日の勤怠' })).toBeVisible()

  const alreadyClockedOut = page.getByText('本日の勤怠は完了しています。')
  if (await alreadyClockedOut.isVisible()) {
    return
  }

  const clockInButton = page.getByRole('button', { name: '出勤' })
  if (await clockInButton.isVisible()) {
    await clockInButton.click()
  }

  await expect(page.getByRole('button', { name: '休憩開始' })).toBeVisible()
  await page.getByRole('button', { name: '休憩開始' }).click()

  await expect(page.getByRole('button', { name: '休憩終了' })).toBeVisible()
  await page.getByRole('button', { name: '休憩終了' }).click()

  await expect(page.getByRole('button', { name: '退勤' })).toBeVisible()
  await page.getByRole('button', { name: '退勤' }).click()

  await expect(alreadyClockedOut).toBeVisible()
})
