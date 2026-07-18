import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  fetchAttendancePunches,
  recordAttendancePunch,
  submitApproveAndCloseCurrentMonth,
} from './support/api'
import { pickUser } from './support/ui'

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
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    // 承認者が差し戻す。
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await approverPage.goto('/approvals')
    await approverPage.getByRole('row', { name: title }).getByRole('link', { name: title }).click()
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
  await pickUser(page, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
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
    const hrCheckbox = adminPage.getByRole('checkbox', { name: '人事担当者' })
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

test('§5-3: 月次締め後は日次実績が編集できない', async ({ browser }) => {
  test.setTimeout(60000)

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    // 当月の勤怠月次を提出〜承認〜締めまで進める(UC-A008〜UC-A011)。同一日に何度
    // 実行しても、既に進んでいるステータスはスキップするため冪等に動く。
    const { workDate } = await submitApproveAndCloseCurrentMonth(applicantPage, approverPage, adminPage)

    // 締め済みの日を日次勤怠画面(`/attendance/days/{date}`)で編集しようとすると、
    // 保存時にブロックされる(UC-A011)。日次実績の編集・削除操作は週次画面のインライン
    // ボタンではなく専用の日次勤怠画面に集約されている(2026-07時点でのUI変更、
    // scenario-06-attendance-corrections.spec.ts冒頭コメント参照)。
    await applicantPage.goto(`/attendance/days/${workDate}`)
    // 「ログを編集」(打刻ログカード)と区別するため完全一致で指定する。
    await applicantPage.getByRole('button', { name: '編集', exact: true }).click()
    await applicantPage.getByLabel('修正理由(必須)').fill('締め後編集の拒否確認(E2E)')
    await applicantPage.getByRole('button', { name: '保存する' }).click()

    await expect(
      applicantPage.getByRole('alert').filter({ hasText: '締め後の勤怠は修正申請から変更してください。' }),
    ).toBeVisible()
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})

test('§5-4: 打刻ログと日次実績の不一致確認', async ({ page }) => {
  test.setTimeout(30000)

  // 週送りの操作を1回で済ませるため、月曜起点の週が必ず1つ先になる7日後を対象日にする
  // (今日がどの曜日でも、7日後は常に「次週」の同じ曜日になる)。
  const futureDate = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

  await loginAs(page, SCENARIO_USERS.punchEmployee)

  // 出勤打刻を2回記録する(clock_inがちょうど1件でない=矛盾)。打刻ログ自体は矛盾が
  // あっても常に記録される(UC-A012)。
  await recordAttendancePunch(page, {
    workDate: futureDate,
    punchType: 'clock_in',
    punchedAt: `${futureDate}T09:00:00+09:00`,
  })
  await recordAttendancePunch(page, {
    workDate: futureDate,
    punchType: 'clock_in',
    punchedAt: `${futureDate}T09:05:00+09:00`,
  })

  // 打刻ログの削除API(UC-A014)はあるが、このテストでは使わずに残したままにしている。
  // 同一日に再実行すると前回分の打刻ログが残ったままになるため、矛盾の有無を見たいだけの
  // このテストでは正確な件数ではなく2件以上あることだけ確認する。
  const punches = await fetchAttendancePunches(page, futureDate, futureDate)
  expect(punches.length).toBeGreaterThanOrEqual(2)

  // 矛盾があるためattendance_daysには反映されず、週次画面でもその日は未入力のままになる
  // (専用の「要確認」バッジ等のUIはまだ無いため、ステータスバッジが「未入力」のままで
  // あることで確認する)。
  await page.goto('/attendance/week')
  await page.getByRole('button', { name: '次週' }).click()
  const futureRow = page.getByRole('listitem').filter({ hasText: futureDate })
  await expect(futureRow).toBeVisible()
  await expect(futureRow.getByRole('status', { name: '未入力' })).toBeVisible()
})

// §5-5: 有給の自動失効警告・年5日取得義務警告バッチ(WarnExpiringPaidLeave /
// WarnFiveDayObligation)は、送信結果を確認できるAPI・画面が無く(Teams通知ジョブを
// キューに積むのみ)、本ファイルが前提とするブラックボックスE2E(HTTP/画面操作のみで
// 完結)では検証できない。シナリオ6(通年運用シミュレーション)の手順4が同じ制約を
// 持つバッチ(grant-scheduledも含む3つ)を、ドキュメントで明示的に許容された例外として
// `child_process`経由でartisanコマンドを直接実行する方式で検証しているため、
// `scenario-08-fiscal-year-cycle.spec.ts`の`境界条件の単発確認`を参照。

test('§5-8: 締めた月の勤怠CSV出力', async ({ browser }) => {
  test.setTimeout(60000)

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    const { yearMonth } = await submitApproveAndCloseCurrentMonth(applicantPage, approverPage, adminPage)

    await adminPage.goto('/admin/attendance-export')
    await adminPage.getByLabel('対象月').fill(yearMonth)

    const [download] = await Promise.all([
      adminPage.waitForEvent('download'),
      adminPage.getByRole('button', { name: 'CSVダウンロード' }).click(),
    ])
    const csvPath = await download.path()
    const csv = csvPath ? await readFile(csvPath, 'utf-8') : ''

    expect(csv).toContain(SCENARIO_USERS.punchEmployee)
    expect(csv).toContain(yearMonth)
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})

test('§5-9: Entra ID初回ログイン(新入社員オンボーディング)', async ({ page }) => {
  test.setTimeout(30000)

  // mock-oidcのユーザーのうちScenarioSeederが意図的に未使用のまま残している3人
  // (docs/testing/scenario-tests.md §3)。ユーザーを未登録状態へ戻すAPIが無いため、
  // 複数回実行すると2人目・3人目...と順に初回ログインを消費していく
  // (環境ごとに検証できるのは最大3回まで。ランダムに選び衝突の可能性を下げる)。
  const newHireCandidates = ['山田 太郎', '佐藤 花子', '鈴木 一郎']
  const newHireName = newHireCandidates[Math.floor(Math.random() * newHireCandidates.length)]

  await loginAs(page, newHireName)

  const me = await page.evaluate(async () => {
    const token = localStorage.getItem('flow-office.token')
    const res = await fetch('http://localhost:8000/api/auth/me', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    return res.json()
  })
  // 初回ログインでは自動的にemployeeロールが付与される(UC-001)。
  expect(me.roles).toContain('employee')
  const userId = me.id

  // 管理者が入社日を設定し(UC-P002)、hr_staffロールを追加する(UC-M001)。
  const adminContext = await page.context().browser()!.newContext()
  const adminPage = await adminContext.newPage()
  try {
    await loginAs(adminPage, SCENARIO_USERS.admin)
    await adminPage.goto(`/admin/users/${userId}`)

    await adminPage.getByLabel('入社日(有給の自動付与に使用)').fill('2026-04-01')
    await adminPage.getByRole('button', { name: '入社日を保存する' }).click()
    await expect(adminPage.getByRole('status', { name: '保存しました' })).toBeVisible()

    await adminPage.getByRole('checkbox', { name: '人事担当者' }).check()
    await adminPage.getByRole('button', { name: '保存する', exact: true }).click()
    await expect(adminPage.getByRole('status', { name: '保存しました' }).first()).toBeVisible()
  } finally {
    await adminContext.close()
  }
})
