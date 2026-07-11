import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { fetchExpensesCsv } from './support/api'
import { pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md シナリオ4(交通費申請)。
 * 申請(request_type_code=commuting_expense)〜承認〜経理バックオフィスタスク処理〜
 * 経費CSV出力までの一連の流れ。
 */
test('交通費申請〜承認〜経理タスク処理〜CSV出力', async ({ browser }) => {
  test.setTimeout(60000)
  const amount = String(1000 + Math.floor(Math.random() * 8000))
  const title = `E2Eテスト交通費申請_${amount}`

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const accountingContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const accountingPage = await accountingContext.newPage()

    // 1. 高橋健太で交通費精算を下書き作成〜提出する(承認者=渡辺直樹)。
    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/requests/new')
    await applicantPage.getByLabel('申請種別').selectOption({ label: '交通費精算' })
    await applicantPage.getByLabel('タイトル').fill(title)
    await applicantPage.getByLabel('金額').fill(amount)
    await applicantPage.getByLabel('経路').fill('自宅最寄駅→本社最寄駅')
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('heading', { name: title })).toBeVisible()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    // 2. 渡辺直樹が承認する。承認によりバックオフィスタスク(経理向け)が自動生成される。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    const approvalRow = approverPage.getByRole('row', { name: title })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('link', { name: title }).click()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    // 3. 小林誠(経理担当者)が未担当タスクを自分に割り当て、
    //    processing → payment_scheduled → completed の順にステータス変更する。
    await loginAs(accountingPage, SCENARIO_USERS.accountingStaff)
    await accountingPage.goto('/backoffice-tasks')
    const taskRow = accountingPage.getByRole('row', { name: title })
    await expect(taskRow).toBeVisible()
    await taskRow.getByRole('link', { name: title }).click()

    await pickUser(accountingPage, '担当者', SCENARIO_USERS.accountingStaff, 'makoto.kobayashi@example.com')
    await accountingPage.getByRole('button', { name: '割り当てる' }).click()
    await expect(accountingPage.getByText('未割り当て')).toHaveCount(0)

    const statusSteps: Array<{ value: string; label: string }> = [
      { value: 'processing', label: '処理中' },
      { value: 'payment_scheduled', label: '支払予定' },
      { value: 'completed', label: '完了' },
    ]
    for (const step of statusSteps) {
      await accountingPage.getByLabel('状態').selectOption(step.value)
      await accountingPage.getByRole('button', { name: '更新する' }).click()
      // 前段の更新が反映されてから次のステータス変更に進む(連打による競合を避ける)。
      await expect(accountingPage.getByRole('status', { name: step.label })).toBeVisible()
    }

    // 4. 経費CSV(UC-E001)に今回の金額が含まれることを確認する
    //    (2026-07-10時点、経費CSV出力の画面はまだ未実装のためAPIを直接叩く)。
    const yesterday = new Date()
    yesterday.setDate(yesterday.getDate() - 1)
    const tomorrow = new Date()
    tomorrow.setDate(tomorrow.getDate() + 1)
    const csv = await fetchExpensesCsv(
      accountingPage,
      yesterday.toISOString().slice(0, 10),
      tomorrow.toISOString().slice(0, 10),
    )
    expect(csv).toContain(amount)
    expect(csv).toContain('completed')

    // 5. 高橋健太が申請の履歴でステータス変遷を確認する(申請詳細画面を開いたままなのでreloadのみ)。
    await applicantPage.reload()
    const applicantMain = applicantPage.getByRole('main')
    await expect(applicantMain.getByRole('status', { name: '承認済み' })).toBeVisible()
    const historyList = applicantMain.getByRole('list', { name: '履歴' })
    await expect(historyList.getByRole('listitem').filter({ hasText: '提出' })).toBeVisible()
    await expect(historyList.getByRole('listitem').filter({ hasText: '承認' })).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await accountingContext.close()
  }
})
