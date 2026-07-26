import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { fetchExpensesCsv } from './support/api'
import { pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md シナリオ4(経費精算、旧・交通費申請)。
 * 経費精算専用ドメイン(docs/30-usecases-expense.md)への移行後の一連の流れ:
 * 経費精算の新規作成〜明細入力〜申請〜承認〜経理バックオフィスタスク処理〜経費CSV出力。
 * 通勤費・業務交通費・その他経費はすべて`expense_claims`/`expense_items`に統合されており、
 * 汎用申請ワークフロー(request_types)は経由しない。
 */
test('経費精算(交通費)の新規作成〜申請〜承認〜経理タスク処理〜CSV出力', async ({ browser }) => {
  test.setTimeout(60000)

  // 同一日に何度実行しても一覧上の行が一意に識別できるよう、対象日を遠い未来のランダムな日に
  // ずらす(バックオフィスタスクのタイトルは金額を含まず「経費精算: 氏名(期間)」形式のため)。
  const amount = String(1000 + Math.floor(Math.random() * 8000))
  const daysAhead = 1000 + Math.floor(Math.random() * 8000)
  const usageDate = new Date()
  usageDate.setDate(usageDate.getDate() + daysAhead)
  const usageDateStr = usageDate.toISOString().slice(0, 10)

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const accountingContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const accountingPage = await accountingContext.newPage()

    // 1. 高橋健太が経費精算(交通費)を新規作成〜明細入力〜提出する(承認者=渡辺直樹)。
    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/expenses/new')
    await applicantPage.getByLabel('対象期間(開始)').fill(usageDateStr)
    await applicantPage.getByLabel('対象期間(終了)').fill(usageDateStr)
    await applicantPage.getByRole('button', { name: '作成して明細入力へ進む' }).click()

    await expect(applicantPage.getByRole('tab', { name: '表形式入力' })).toBeVisible()
    await applicantPage.getByRole('button', { name: '行を追加' }).click()
    await applicantPage.getByLabel('1行目の日付').fill(usageDateStr)
    await applicantPage.getByLabel('1行目の金額').fill(amount)
    await applicantPage.getByRole('button', { name: /明細を保存する/ }).click()

    await expect(applicantPage.getByText(`保存済みの明細(1件)`)).toBeVisible()

    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '申請する' }).click()

    await expect(applicantPage.getByRole('status', { name: '申請中' })).toBeVisible()
    const claimUrl = applicantPage.url()

    // 2. 渡辺直樹が承認待ち一覧から開いて承認する。承認によりバックオフィスタスク(経理向け)が
    //    自動生成される。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/expenses/to-approve')
    const approvalRow = approverPage.getByRole('row', { name: new RegExp(usageDateStr) })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('link').click()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    // 3. 小林誠(経理担当者)が未担当タスクを自分に割り当て、
    //    確認中(割り当て時に自動遷移)→支払予定→完了の順にステータス変更する。
    await loginAs(accountingPage, SCENARIO_USERS.accountingStaff)
    await accountingPage.goto('/backoffice-tasks')
    const taskTitle = /経費精算.*高橋 健太/
    const taskRow = accountingPage.getByRole('row', { name: taskTitle })
    await expect(taskRow).toBeVisible()
    await taskRow.getByRole('link').click()

    await pickUser(accountingPage, '担当者', SCENARIO_USERS.accountingStaff, 'makoto.kobayashi@example.com')
    await accountingPage.getByRole('button', { name: '割り当てる' }).click()
    await expect(accountingPage.getByText('未割り当て')).toHaveCount(0)

    // request_types方式の廃止後もtask_type='expense_reimbursement'固定の許可遷移
    // (未着手→確認中→支払予定→完了)は維持されている。割り当て時点で自動的に確認中になる。
    const statusSteps: Array<{ value: string; label: string }> = [
      { value: 'payment_scheduled', label: '支払予定' },
      { value: 'completed', label: '完了' },
    ]
    for (const step of statusSteps) {
      await accountingPage.getByLabel('状態').selectOption(step.value)
      await accountingPage.getByRole('button', { name: '更新する' }).click()
      await expect(accountingPage.getByRole('status', { name: step.label })).toBeVisible()
    }

    // 4. 経費CSV(UC-E001)に今回の金額が含まれることを確認する
    //    (対象日を基準にした前後1日の範囲で絞り込む)。
    const from = new Date(usageDate)
    from.setDate(from.getDate() - 1)
    const to = new Date(usageDate)
    to.setDate(to.getDate() + 1)
    const csv = await fetchExpensesCsv(accountingPage, from.toISOString().slice(0, 10), to.toISOString().slice(0, 10))
    expect(csv).toContain(amount)
    expect(csv).toContain('completed')

    // 5. 高橋健太が経費精算の詳細画面でステータス変遷・履歴を確認する。
    await applicantPage.goto(claimUrl)
    await expect(applicantPage.getByRole('status', { name: '承認済み' })).toBeVisible()
    const historyList = applicantPage.getByRole('list', { name: '履歴' })
    await expect(historyList.getByRole('listitem').filter({ hasText: '提出' })).toBeVisible()
    await expect(historyList.getByRole('listitem').filter({ hasText: '承認' })).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await accountingContext.close()
  }
})
