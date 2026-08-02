import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { fetchExpensesCsv } from './support/api'
import { pickUser } from './support/ui'

const CURRENT_DIR = path.dirname(fileURLToPath(import.meta.url))
// 経費明細の添付ファイルはpdf/jpg/jpeg/pngのみ許可される(AttachmentController::EXPENSE_ITEM_ALLOWED_EXTENSIONS)。
const DUMMY_RECEIPT_PATH = path.resolve(CURRENT_DIR, 'support/fixtures/dummy-receipt.png')

/**
 * docs/testing/scenario-tests.md シナリオ4(経費精算、旧・交通費申請)。
 * 経費精算専用ドメイン(docs/30-usecases-expense.md)への移行、および個別/まとめて登録の
 * 入口選択・入力プリセット導入後のUIに合わせて全面的に書き直した。通勤費・業務交通費・
 * 会食・宿泊費・消耗品・その他経費はすべて`expense_claims`/`expense_items`に統合されており、
 * 汎用申請ワークフロー(request_types)は経由しない。
 *
 * 前半(交通費)は新規作成〜明細入力〜申請〜承認〜経理バックオフィスタスク処理〜経費CSV出力
 * までのフルサイクルを確認する。中盤は残り4区分(宿泊費・会食・消耗品・その他)それぞれの
 * 単一申請シナリオ(個別登録→区分専用フォームへの入力→申請→承認)を確認する。後半は
 * 「まとめて経費登録」(タイトル先入力→複数区分にまたがる複数明細→申請→承認)を確認する。
 */

/** 同一日に何度実行しても一覧上の行が一意に識別できるよう、対象日を遠い未来のランダムな
 *  日にずらす(バックオフィスタスクのタイトルは金額を含まず「経費精算: 氏名(期間)」形式のため)。 */
function randomFutureDate(): string {
  const daysAhead = 1000 + Math.floor(Math.random() * 8000)
  const date = new Date()
  date.setDate(date.getDate() + daysAhead)
  return date.toISOString().slice(0, 10)
}

/** UC-X004: 新規作成の入口で「個別に経費登録」を選び、経費区分選択ステップへ進む。 */
async function startIndividualClaim(page: Page): Promise<void> {
  await page.goto('/expenses/new')
  await page.getByRole('button', { name: '個別に登録する' }).click()
}

/**
 * `expense_categories.approval_skip_threshold`(交通費=3000円、消耗品=1000円)を超える
 * 金額をランダムに生成する。しきい値以下だと申請が即承認扱いになり「申請中」を経由しないため、
 * 承認フローそのものを確認したいこのテストでは必ずしきい値を超える金額にする。
 */
function randomAmountAboveApprovalSkipThreshold(): string {
  return String(5000 + Math.floor(Math.random() * 4000))
}

/**
 * `expense_categories.receipt_required_threshold`(消耗品・その他=3000円)を超える金額で
 * 保存した明細には領収書の添付が必須になる(宿泊費・会食は金額によらず常に必須)。
 * 明細の入力時点(単発入力フォームの「領収書(任意)」欄)でダミーファイルを選択する。
 * 明細の保存と同時にアップロードされるため、保存後に別途添付する操作は不要になる。
 */
async function selectReceiptOnSingleItemForm(page: Page): Promise<void> {
  await page.getByLabel('領収書(任意)').setInputFiles(DUMMY_RECEIPT_PATH)
}

/**
 * 表形式(まとめ)入力で、指定した行の「領収書」ファイル入力にダミーファイルを選択する。
 * 明細を保存すると、行ごとに選択したファイルがそれぞれの明細に紐づけてアップロードされる。
 */
async function selectReceiptOnBatchRow(page: Page, rowNumber: number): Promise<void> {
  await page.getByLabel(`${rowNumber}行目の領収書`).setInputFiles(DUMMY_RECEIPT_PATH)
}

/**
 * ExpenseClaimNewPage.tsxの`suggestedBulkTitles()`と同じロジックで、
 * 「まとめて経費登録」のタイトル候補チップの文言を組み立てる。
 */
function suggestedBulkTitles(): string[] {
  const now = new Date()
  const label = `${now.getFullYear()}年${now.getMonth() + 1}月分`
  return [`${label}経費`, `${label}交通費`, '出張精算']
}

test('経費精算(交通費)の新規作成〜申請〜承認〜経理タスク処理〜CSV出力', async ({ browser }) => {
  test.setTimeout(60000)

  const amount = randomAmountAboveApprovalSkipThreshold()
  const usageDateStr = randomFutureDate()

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const accountingContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const accountingPage = await accountingContext.newPage()

    // 1. 高橋健太が「個別に経費登録」から交通費を選び、出発地・到着地を入力して
    //    明細を保存〜申請する(承認者=渡辺直樹)。
    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await startIndividualClaim(applicantPage)
    await applicantPage.getByRole('button', { name: '交通費' }).click()

    await applicantPage.getByRole('button', { name: '行を追加' }).click()
    await applicantPage.getByLabel('1行目の日付').fill(usageDateStr)
    await applicantPage.getByLabel('1行目の金額').fill(amount)
    // UC-X004a: 出発地・到着地を専用欄に入力すると「出発地 → 到着地」の形式で内容欄に
    // 自動的に反映される(交通費特有の構造化入力補助)。
    await applicantPage.getByLabel('1行目の出発地').fill('自宅')
    await applicantPage.getByLabel('1行目の到着地').fill('本社')
    await expect(applicantPage.getByLabel('1行目の内容')).toHaveValue('自宅 → 本社')
    // 明細の保存より前に、表形式入力の行ごとの領収書欄でファイルを選択できることを確認する。
    await selectReceiptOnBatchRow(applicantPage, 1)

    await applicantPage.getByRole('button', { name: /明細を保存する/ }).click()

    await expect(applicantPage.getByText(/保存済みの明細\(1件/)).toBeVisible()
    await expect(applicantPage.getByText('dummy-receipt.png')).toBeVisible()

    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '申請する' }).click()

    await expect(applicantPage.getByRole('status', { name: '申請中' })).toBeVisible()
    const claimUrl = applicantPage.url()

    // 2. 渡辺直樹が承認待ち一覧から開いて承認する。承認によりバックオフィスタスク(経理向け)が
    //    自動生成される。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    // 個別登録では申請タイトルは既定値「経費精算」のまま(明細のusage_dateは統合承認画面の
    // 一覧行には表示されないため、代わりに申請者名で行を特定する)。
    const approvalRow = approverPage.getByRole('row', { name: '経費精算' }).filter({ hasText: SCENARIO_USERS.punchEmployee })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('button', { name: '経費精算' }).click()
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

    // 4. 経費CSV(UC-E001)に今回の金額が含まれることを確認する。
    //    エクスポート対象はバックオフィスタスクの`created_at`(=承認された今日の日時)で
    //    絞り込まれる(明細のusage_dateではない)ため、今日を基準にした前後1日の範囲で絞り込む。
    const today = new Date()
    const from = new Date(today)
    from.setDate(from.getDate() - 1)
    const to = new Date(today)
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

/**
 * UC-X004b〜d: 残り4区分(宿泊費・会食・消耗品・その他)それぞれの単一申請シナリオ。
 * 「個別に経費登録」→区分専用フォームへの1件入力→申請→承認までを1本ずつ確認する。
 * 区分ごとに入力項目・必須項目が異なる(SingleExpenseItemForm の fieldSet)ため、
 * それぞれ専用の入力手順を持つ。
 */
const singleCategoryScenarios: Array<{
  categoryButtonName: string
  fill: (page: Page, usageDateStr: string, amount: string) => Promise<void>
}> = [
  {
    categoryButtonName: '宿泊費',
    fill: async (page, usageDateStr, amount) => {
      await page.getByLabel('利用日').fill(usageDateStr)
      await page.getByLabel('金額').fill(amount)
      await page.getByLabel('宿泊先名').fill('ホテルABC')
      await page.getByLabel('内容').fill('出張1泊')
    },
  },
  {
    categoryButtonName: '会食',
    fill: async (page, usageDateStr, amount) => {
      await page.getByLabel('利用日').fill(usageDateStr)
      await page.getByLabel('金額').fill(amount)
      await page.getByLabel('取引先').fill('居酒屋 花')
      await page.getByLabel('参加者氏名').fill('山田太郎、鈴木一郎')
      await page.getByLabel('参加人数').fill('2')
      await page.getByLabel('内容').fill('取引先との懇親会')
    },
  },
  {
    categoryButtonName: '消耗品',
    fill: async (page, usageDateStr, amount) => {
      await page.getByLabel('利用日').fill(usageDateStr)
      await page.getByLabel('金額').fill(amount)
      // 消耗品は購入店舗が明確なことが多いため、取引先(店名)を必須項目のままにしている。
      await page.getByLabel('取引先').fill('文具店')
      await page.getByLabel('内容').fill('ノート・ペン購入')
    },
  },
  {
    categoryButtonName: 'その他',
    fill: async (page, usageDateStr, amount) => {
      await page.getByLabel('利用日').fill(usageDateStr)
      await page.getByLabel('金額').fill(amount)
      // 「その他」は取引先が無い経費(郵送料の実費精算等)もあり得るため取引先は入力せず、
      // 内容欄だけで申請できることを確認する。
      await page.getByLabel('内容').fill('郵送料の実費精算')
    },
  },
]

for (const scenario of singleCategoryScenarios) {
  test(`経費精算(${scenario.categoryButtonName})の単一申請〜承認`, async ({ browser }) => {
    test.setTimeout(30000)

    // 消耗品(receipt_required_threshold=1000/3000)・その他(同3000)がレシート必須にならない
    // 金額だとレシート添付確認ができないため、いずれのしきい値も超える金額にする。
    const amount = randomAmountAboveApprovalSkipThreshold()
    const usageDateStr = randomFutureDate()

    const applicantContext = await browser.newContext()
    const approverContext = await browser.newContext()

    try {
      const applicantPage = await applicantContext.newPage()
      const approverPage = await approverContext.newPage()

      await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
      await startIndividualClaim(applicantPage)
      await applicantPage.getByRole('button', { name: scenario.categoryButtonName, exact: true }).click()

      await scenario.fill(applicantPage, usageDateStr, amount)
      // 宿泊費・会食は常に、消耗品・その他はこの金額(3000円超)ではレシート添付が必須。
      // 保存する前にフォーム上の「領収書(任意)」欄でファイルを選択し、明細作成と同時に
      // 添付されることを確認する。
      await selectReceiptOnSingleItemForm(applicantPage)
      await applicantPage.getByRole('button', { name: '明細を保存して続けて入力する' }).click()

      await expect(applicantPage.getByText(/保存済みの明細\(1件/)).toBeVisible()
      await expect(applicantPage.getByText('dummy-receipt.png')).toBeVisible()

      await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
      await applicantPage.getByRole('button', { name: '申請する' }).click()
      await expect(applicantPage.getByRole('status', { name: '申請中' })).toBeVisible()
      const claimUrl = applicantPage.url()

      await loginAs(approverPage, SCENARIO_USERS.approver)
      await approverPage.goto('/approvals')
      const approvalRow = approverPage.getByRole('row', { name: '経費精算' }).filter({ hasText: SCENARIO_USERS.punchEmployee })
      await expect(approvalRow).toBeVisible()
      await approvalRow.getByRole('button', { name: '経費精算' }).click()
      await approverPage.getByRole('button', { name: '承認する' }).click()
      await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

      await applicantPage.goto(claimUrl)
      await expect(applicantPage.getByRole('status', { name: '承認済み' })).toBeVisible()
    } finally {
      await applicantContext.close()
      await approverContext.close()
    }
  })
}

/**
 * 「まとめて経費登録」(タイトルを先に入力してから複数区分の明細を積み上げる)シナリオ。
 * 個別登録との違いは (1) 区分選択の前にタイトル入力ステップがあり、候補チップを
 * クリックするだけでタイトル確定と同時に下書きが作成される点、(2) 交通費(複数行の
 * まとめ入力)と宿泊費(区分をまたいだ追加、UC-X013)の明細を1つの申請にまとめる点。
 */
test('経費精算(まとめて登録)の新規作成〜複数区分明細入力〜申請〜承認', async ({ browser }) => {
  test.setTimeout(60000)

  const transportAmount1 = randomAmountAboveApprovalSkipThreshold()
  const transportAmount2 = randomAmountAboveApprovalSkipThreshold()
  const lodgingAmount = randomAmountAboveApprovalSkipThreshold()
  // claim.period_from/period_toは保存済み明細のusage_dateの最小値・最大値から自動算出される
  // (原則2)。承認待ち一覧の行は期間表示で特定するため、3件の日付を昇順で固定しておく。
  const baseDate = new Date(`${randomFutureDate()}T00:00:00`)
  const usageDateStr1 = baseDate.toISOString().slice(0, 10)
  const usageDateStr2 = new Date(baseDate.getTime() + 1 * 86400000).toISOString().slice(0, 10)
  const usageDateStr3 = new Date(baseDate.getTime() + 2 * 86400000).toISOString().slice(0, 10)
  const title = suggestedBulkTitles()[1] // "YYYY年M月分交通費"

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()

    // 1. 高橋健太が「まとめて経費登録」を選び、タイトル候補チップをクリックして
    //    下書き作成とタイトル確定を同時に行う(明細入力より前にタイトルが決まる点が
    //    個別登録との違い)。
    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await applicantPage.goto('/expenses/new')
    await applicantPage.getByRole('button', { name: 'まとめて登録する' }).click()
    await applicantPage.getByRole('button', { name: title, exact: true }).click()

    // タイトル確定後、区分選択画面に進む(対象期間はまだ聞かれない)。
    await expect(applicantPage.getByRole('button', { name: '交通費' })).toBeVisible()

    // 2. 交通費を選び、2件まとめて保存する。
    await applicantPage.getByRole('button', { name: '交通費' }).click()
    await applicantPage.getByRole('button', { name: '行を追加' }).click()
    await applicantPage.getByLabel('1行目の日付').fill(usageDateStr1)
    await applicantPage.getByLabel('1行目の金額').fill(transportAmount1)
    await applicantPage.getByLabel('1行目の出発地').fill('自宅')
    await applicantPage.getByLabel('1行目の到着地').fill('本社')
    await applicantPage.getByRole('button', { name: '行を追加' }).click()
    await applicantPage.getByLabel('2行目の日付').fill(usageDateStr2)
    await applicantPage.getByLabel('2行目の金額').fill(transportAmount2)
    await applicantPage.getByLabel('2行目の出発地').fill('本社')
    await applicantPage.getByLabel('2行目の到着地').fill('客先')
    // 表形式入力でも、保存前に行ごとに領収書を選択できることを確認する(1行目のみ添付)。
    await selectReceiptOnBatchRow(applicantPage, 1)
    await applicantPage.getByRole('button', { name: /明細を保存する\(2件\)/ }).click()

    await expect(applicantPage.getByText(/保存済みの明細\(2件/)).toBeVisible()
    await expect(applicantPage.getByText('dummy-receipt.png')).toBeVisible()

    // 3. UC-X013: 別の区分(宿泊費)の明細を同じ申請に追加する。
    await applicantPage.getByRole('button', { name: '別の区分の明細を追加する' }).click()
    await applicantPage.getByRole('button', { name: '宿泊費', exact: true }).click()
    await applicantPage.getByLabel('利用日').fill(usageDateStr3)
    await applicantPage.getByLabel('金額').fill(lodgingAmount)
    await applicantPage.getByLabel('宿泊先名').fill('ホテルXYZ')
    // 宿泊費は金額によらずレシート必須。単発入力フォームの「領収書(任意)」欄で
    // 保存前に選択する。
    await selectReceiptOnSingleItemForm(applicantPage)
    await applicantPage.getByRole('button', { name: '明細を保存して続けて入力する' }).click()

    await expect(applicantPage.getByText(/保存済みの明細\(3件/)).toBeVisible()

    // タイトルはタイトル入力ステップで確定済みのまま変更していないことを確認する。
    await expect(applicantPage.getByLabel('申請タイトル')).toHaveValue(title)

    // 4. 承認者を指定して申請する。
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '申請する' }).click()
    await expect(applicantPage.getByRole('status', { name: '申請中' })).toBeVisible()
    const claimUrl = applicantPage.url()

    // 5. 渡辺直樹が統合承認画面(/approvals)で承認する。「まとめて登録」ではタイトルを
    //    先に確定しているため、行はそのタイトルで特定できる。詳細パネルでは明細件数も確認する。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    const approvalRow = approverPage.getByRole('row', { name: title })
    await expect(approvalRow).toBeVisible()
    await approvalRow.getByRole('button', { name: title }).click()
    await expect(approverPage.getByRole('heading', { name: title })).toBeVisible()
    await expect(approverPage.getByRole('heading', { name: '明細(3件)' })).toBeVisible()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    await applicantPage.goto(claimUrl)
    await expect(applicantPage.getByRole('status', { name: '承認済み' })).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
  }
})
