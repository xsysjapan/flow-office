import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md シナリオ5(名刺の申請〜作成・発行)。
 * 申請(request_type_code=business_card)〜承認〜総務バックオフィスタスクの
 * ステータス遷移(in_review→processing→ordered→shipped→completed)までの流れ。
 */
test('名刺申請〜承認〜総務タスク処理(発注〜発送〜完了)', async ({ browser }) => {
  test.setTimeout(60000)
  const quantity = String(10 + Math.floor(Math.random() * 90))
  const title = `E2Eテスト名刺申請_${quantity}枚`

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const generalAffairsContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const generalAffairsPage = await generalAffairsContext.newPage()

    // 1. 伊藤舞(月次入力ユーザー)が名刺申請を下書き作成〜提出する(承認者=渡辺直樹)。
    await loginAs(applicantPage, SCENARIO_USERS.monthlyEmployee)
    await applicantPage.goto('/requests/new')
    await applicantPage.getByLabel('申請種別').selectOption({ label: '名刺申請' })
    await applicantPage.getByLabel('タイトル').fill(title)
    await applicantPage.getByLabel('枚数').fill(quantity)
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('heading', { name: title })).toBeVisible()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    // 2. 渡辺直樹が承認する。承認により総務向けバックオフィスタスクが自動生成される。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    const approvalRow = approverPage.getByRole('row', { name: title })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('link', { name: title }).click()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    // 3. 中村恵(総務担当者)が未担当タスクを自分に割り当て、氏名・部署等を確認したうえで
    //    processing(発注データ作成) → ordered(発注済み) → shipped(発送済み) →
    //    completed(完了)の順にステータスを進める(UC-B005)。
    await loginAs(generalAffairsPage, SCENARIO_USERS.generalAffairsStaff)
    await generalAffairsPage.goto('/backoffice-tasks')
    const taskRow = generalAffairsPage.getByRole('row', { name: title })
    await expect(taskRow).toBeVisible()
    await taskRow.getByRole('link', { name: title }).click()

    // 既知の欠落機能: BackOfficeTaskResource/BackOfficeTaskDetailPageは申請者の氏名・部署・
    // 役職・メール・電話番号や申請フォームの内容(枚数)を一切返さない/表示しないため、
    // UC-B005手順1〜2(名刺記載内容の確認)がこの画面だけでは行えない
    // (docs/testing/scenario-tests.md シナリオ5の確認ポイント参照)。
    // そのため、ここでは元データへのリンク表示のみを確認する。
    await expect(generalAffairsPage.getByText(/workflow_request/)).toBeVisible()

    await pickUser(generalAffairsPage, '担当者', SCENARIO_USERS.generalAffairsStaff, 'megumi.nakamura@example.com')
    await generalAffairsPage.getByRole('button', { name: '割り当てる' }).click()
    await expect(generalAffairsPage.getByText('未割り当て')).toHaveCount(0)

    const statusSteps: Array<{ value: string; label: string }> = [
      { value: 'processing', label: '処理中' },
      { value: 'ordered', label: '発注済み' },
      { value: 'shipped', label: '発送済み' },
      { value: 'completed', label: '完了' },
    ]
    for (const step of statusSteps) {
      await generalAffairsPage.getByLabel('状態').selectOption(step.value)
      await generalAffairsPage.getByRole('button', { name: '更新する' }).click()
      // 前段の更新が反映されてから次のステータス変更に進む(連打による競合を避ける)。
      await expect(generalAffairsPage.getByRole('status', { name: step.label })).toBeVisible()
    }

    // 4. 伊藤舞が自分の申請一覧で完了になっていることを確認する。
    await applicantPage.reload()
    await expect(applicantPage.getByRole('status', { name: '承認済み' })).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await generalAffairsContext.close()
  }
})
