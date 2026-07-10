import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md §5(その他、用意しておくべきシナリオ)に対応する。
 * 実装済みのものは test()、まだのものは test.skip の TODO プレースホルダのまま残す。
 */

test('§5-1: 承認差し戻し→再申請', async ({ browser }) => {
  test.setTimeout(60000)
  const title = `E2Eテスト差戻し確認_${Math.floor(Math.random() * 100000)}`

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/requests/new')
    await applicantPage.getByLabel('申請種別').selectOption({ label: '一般申請' })
    await applicantPage.getByLabel('タイトル').fill(title)
    await applicantPage.getByLabel('内容').fill('E2Eテスト用の一般申請')
    await applicantPage.getByLabel('承認者').fill(SCENARIO_USERS.approver)
    await applicantPage
      .getByRole('button', { name: `${SCENARIO_USERS.approver}(naoki.watanabe@example.com)` })
      .click()
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    // 承認者が差し戻す。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    await approverPage.locator('li', { hasText: title }).getByRole('link', { name: title }).click()
    await approverPage.getByPlaceholder('差戻しコメント').fill('内容を確認してください')
    await approverPage.getByRole('button', { name: '差戻す' }).click()
    await expect(approverPage.getByRole('status', { name: '差戻し' })).toBeVisible()

    // 申請者が差戻しコメントを履歴で確認し、再提出する。
    await applicantPage.reload()
    await expect(applicantPage.getByRole('status', { name: '差戻し' })).toBeVisible()
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    // 承認者が今度は承認する。
    await approverPage.reload()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
  }
})

test('§5-2: 申請取消(提出後)', async ({ page }) => {
  const title = `E2Eテスト取消確認_${Math.floor(Math.random() * 100000)}`

  await loginAs(page, SCENARIO_USERS.punchEmployee)
  await page.goto('/requests/new')
  await page.getByLabel('申請種別').selectOption({ label: '一般申請' })
  await page.getByLabel('タイトル').fill(title)
  await page.getByLabel('内容').fill('E2Eテスト用の一般申請(取消用)')
  await page.getByLabel('承認者').fill(SCENARIO_USERS.approver)
  await page.getByRole('button', { name: `${SCENARIO_USERS.approver}(naoki.watanabe@example.com)` }).click()
  await page.getByRole('button', { name: '提出する' }).click()
  await expect(page.getByRole('status', { name: '提出済み' })).toBeVisible()

  await page.getByPlaceholder('取消理由').fill('申請内容の誤りのため')
  await page.getByRole('button', { name: '取り消す' }).click()
  await expect(page.getByRole('status', { name: '取消' })).toBeVisible()
})

test('§5-6+7: ロール変更が即座に反映され、監査ログに記録される', async ({ page }) => {
  test.setTimeout(60000)

  await loginAs(page, SCENARIO_USERS.approver)
  const userId = await page.evaluate(async () => {
    const token = localStorage.getItem('flow-office.token')
    const res = await fetch('http://localhost:8000/api/auth/me', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    return (await res.json()).id
  })

  const adminContext = await page.context().browser()!.newContext()
  const adminPage = await adminContext.newPage()
  try {
    await loginAs(adminPage, SCENARIO_USERS.admin)

    // このテストを何度も実行しても前提が同じになるよう、まずemployeeのみの状態に戻す
    // (このテストで hr_staff を付与した結果が残っていることがあるため)。
    await adminPage.goto(`/admin/users/${userId}`)
    const hrCheckbox = adminPage.locator('label', { hasText: '人事担当者' }).locator('input[type="checkbox"]')
    if (await hrCheckbox.isChecked()) {
      await hrCheckbox.uncheck()
      await adminPage.getByRole('button', { name: '保存する', exact: true }).click()
      await expect(adminPage.getByRole('status', { name: '保存しました' })).toBeVisible()
    }

    // 対象社員は現状employeeロールのみのため、管理メニューへのリンクがナビゲーションに
    // 出ないことを確認する。
    await page.reload()
    await expect(page.getByRole('link', { name: '管理メニュー' })).toHaveCount(0)

    // 管理者がhr_staffロールを追加する(ログインし直しではなくロールを都度DBに反映する)。
    await adminPage.goto(`/admin/users/${userId}`)
    await hrCheckbox.check()
    await adminPage.getByRole('button', { name: '保存する', exact: true }).click()
    await expect(adminPage.getByRole('status', { name: '保存しました' })).toBeVisible()

    // §5-7: この操作が監査ログに記録されていることを確認する。
    await adminPage.goto('/admin/audit-log')
    await adminPage.getByLabel('対象タイプ').fill('user')
    await adminPage.getByLabel('対象ID').fill(String(userId))
    await expect(adminPage.getByText('user.roles_changed').first()).toBeVisible()
  } finally {
    await adminContext.close()
  }

  // 本人はログインし直さずページをreloadするだけで、新しい権限のナビゲーションが表示される
  // (Sanctumトークン自体は変わらず、/auth/meが最新のrolesを返すことを確認する)。
  await page.reload()
  await expect(page.getByRole('link', { name: '管理メニュー' })).toBeVisible()
  await page.getByRole('link', { name: '管理メニュー' }).click()
  await expect(page.getByRole('complementary').getByRole('link', { name: '有給ルール' })).toBeVisible()
})

test.skip('月次締め後は日次実績が編集できない (TODO, §5-3)', async () => {})
test.skip('打刻ログと日次実績の不一致確認 (TODO, §5-4)', async () => {})
test.skip('有給の自動失効警告・年5日取得義務警告バッチ (TODO, §5-5)', async () => {})
test.skip('締めた月の勤怠CSV出力 (TODO, §5-8)', async () => {})
test.skip('Entra ID初回ログイン(新入社員オンボーディング) (TODO, §5-9)', async () => {})
