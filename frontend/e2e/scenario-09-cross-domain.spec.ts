import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  approvePaidLeaveRequest,
  closeMonth,
  createAttendanceDay,
  createPaidLeaveGrant,
  createWorkStyleViaApi,
  fetchAttendanceMonthDetail,
  fetchEmploymentCategories,
  fetchMonthStatus,
  fetchOwnUserId,
  generateShiftAssignments,
  requestPaidLeave,
  submitAndApproveMonth,
  submitApproveAndCloseCurrentMonth,
  submitMonth,
} from './support/api'
import { pickUser } from './support/ui'

/**
 * docs/testing/scenario-tests.md §5(その他、用意しておくべきシナリオ)項目14〜16に
 * 対応する。項目13(scenario-07-new-work-time-systems.spec.ts)と同様、月内で複数の
 * ユースケースを組み合わせて確認する「その他」の追加分。
 *
 * §5-14・§5-15は対象社員に経理担当者(小林誠)・総務担当者(中村恵)・人事担当者
 * (加藤由美)を使う(§5-15は固定時間制=小林誠、1か月単位変形労働時間制=中村恵、
 * 裁量労働制=加藤由美、管理監督者=高橋健太)。この3人は`ScenarioSeeder`内で
 * `generateShiftAssignments`の対象になっておらず、他のどのシナリオファイルでも
 * 打刻・日次実績作成の対象として使われていない(バックオフィスタスク処理・承認操作の
 * 担当者としてのみ使われる)ため、出勤・シフト・勤務形態の対象者として転用しても
 * 衝突しない。予備枠 mock-entra-user-001〜003(山田太郎・佐藤花子・鈴木一郎)は
 * §5-9(新入社員初回ログイン)専用のため、本ファイルでは消費しない
 * (以前は§5-14・§5-15がこの予備枠を消費していたが、フルスイート実行順
 * (00→...→08→09→99)でscenario-99の§5-9実行時に3人とも初回ログイン済みになって
 * しまい検証が成立しなくなるため、転用可能な既存の登場人物に差し替えた)。§5-16は
 * 月次締めとバックオフィス処理の独立性の確認であり、対象日付を実在の年月から
 * 隔離する必要が無いため、共有ユーザー(高橋健太)をそのまま使う。
 */

/** 指定した年月内の平日("YYYY-MM-DD")を1日から順に列挙する。 */
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

test('§5-14: 有給消化と月60時間超残業判定の同一月内共存', async ({ browser }) => {
  test.setTimeout(120000)
  // scenario-00/07(3000〜8999年度)・scenario-08(実在の2026〜2027年)と衝突しない
  // 専用レンジ。実行のたびにランダムな年を選び、複数回実行しても前回分と衝突しないようにする。
  const year = 9000 + Math.floor(Math.random() * 400)
  const yearMonth = `${year}-06`

  const employeeContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(employeePage, SCENARIO_USERS.accountingStaff)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    const userId = await fetchOwnUserId(employeePage)
    const approverId = await fetchOwnUserId(approverPage)

    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `cx14_${year}`,
      name: `E2E 有給+60時間超残業共存確認${year}`,
      workTimeSystem: 'fixed',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
    })
    await generateShiftAssignments(adminPage, {
      userId,
      workStyleId: workStyle.id,
      from: `${yearMonth}-01`,
      to: `${yearMonth}-30`,
    })

    // grantAdditionalPaidLeaveは実際の「今日」基準の失効日を使うため、この遠い未来の
    // 年月には使えない(失効日が対象日より前になってしまう)。対象月をカバーする
    // 失効日を指定して個別に付与する。
    await createPaidLeaveGrant(adminPage, {
      userId,
      grantedOn: `${year}-04-01`,
      expiresOn: `${year + 2}-03-31`,
      days: 10,
      reason: 'E2Eテスト用付与(§5-14)',
    })

    const weekdays = weekdaysOfMonth(year, 6)
    const [fullLeaveDate, halfLeaveDate, ...rest] = weekdays
    // 1日8時間(480分)を超える分がそのまま法定外残業になる固定時間制で、1日240分
    // (12時間勤務、休憩1時間)の法定外残業を16日分積み上げる(240分×16日=3840分=64時間、
    // 月60時間(3600分)の判定を確実に超える)。
    const overtimeDates = rest.slice(0, 16)

    // (a) 全休・半休で有給を消化する。
    const fullRequest = await requestPaidLeave(employeePage, {
      targetDate: fullLeaveDate,
      leaveType: 'full',
      approverUserId: approverId,
      reason: 'E2Eテスト全休(§5-14)',
    })
    await approvePaidLeaveRequest(approverPage, fullRequest.id)

    const halfRequest = await requestPaidLeave(employeePage, {
      targetDate: halfLeaveDate,
      leaveType: 'am_half',
      approverUserId: approverId,
      reason: 'E2Eテスト午前半休(§5-14)',
    })
    await approvePaidLeaveRequest(approverPage, halfRequest.id)

    // (b) 別の日で法定外残業を積み上げて月60時間を超えさせる。
    for (const date of overtimeDates) {
      await createAttendanceDay(employeePage, {
        userId,
        workDate: date,
        actualStartAt: `${date}T09:00:00+09:00`,
        actualEndAt: `${date}T22:00:00+09:00`,
        breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
        reason: '法定外残業積み上げ(E2E, §5-14)',
      })
    }

    // 有給消化(全休1.0日+午前半休0.5日=1.5日)は実労働時間に加算されない(既知の仕様、
    // docs/09-usecases-paid-leave.md参照)ことを踏まえ、実働日だけで月60時間超残業が
    // 正しく積算されていることを確認する。
    const detail = await fetchAttendanceMonthDetail(employeePage, yearMonth)
    expect(detail.monthly_calculation_totals.paid_leave_days).toBe(1.5)
    expect(detail.monthly_calculation_totals.statutory_excess_overtime_minutes).toBeGreaterThan(3600)
    expect(detail.monthly_calculation_totals.statutory_excess_overtime_within_60h_minutes).toBe(3600)
    expect(detail.monthly_calculation_totals.statutory_excess_overtime_over_60h_minutes).toBeGreaterThan(0)

    // 有給消化日を挟んだ月でも、月次提出〜承認〜締めまで支障なく進められることを確認する。
    await submitAndApproveMonth(employeePage, approverPage, yearMonth)
    await closeMonth(adminPage, employeePage, yearMonth)
    expect(await fetchMonthStatus(employeePage, yearMonth)).toBe('closed')
  } finally {
    await employeeContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})

test('§5-15: 複数の労働時間制度が混在する月の月次締め', async ({ browser }) => {
  test.setTimeout(120000)
  // §5-14(9000年台)と衝突しない専用レンジ。
  const year = 9500 + Math.floor(Math.random() * 400)
  const yearMonth = `${year}-05`
  const from = `${yearMonth}-01`
  const to = `${yearMonth}-31`

  const fixedContext = await browser.newContext()
  const variableContext = await browser.newContext()
  const discretionaryContext = await browser.newContext()
  const managerContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const fixedPage = await fixedContext.newPage()
    const variablePage = await variableContext.newPage()
    const discretionaryPage = await discretionaryContext.newPage()
    const managerPage = await managerContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    // 固定時間制=小林誠(経理担当者)、1か月単位変形労働時間制=中村恵(総務担当者)、
    // 裁量労働制=加藤由美(人事担当者)、管理監督者=高橋健太(共有ユーザーだが、この年月は
    // 他シナリオが触れない専用レンジ)。
    await loginAs(fixedPage, SCENARIO_USERS.accountingStaff)
    await loginAs(variablePage, SCENARIO_USERS.generalAffairsStaff)
    await loginAs(discretionaryPage, SCENARIO_USERS.hrStaff)
    await loginAs(managerPage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    const approverId = await fetchOwnUserId(approverPage)
    const employmentCategories = await fetchEmploymentCategories(adminPage)
    const regular = employmentCategories.find((c) => c.code === 'regular')

    const actors = [
      { page: fixedPage, name: '小林誠(固定時間制)' },
      { page: variablePage, name: '中村恵(1か月単位変形労働時間制)' },
      { page: discretionaryPage, name: '加藤由美(裁量労働制)' },
      { page: managerPage, name: '高橋健太(管理監督者)' },
    ]
    const userIds = await Promise.all(actors.map((actor) => fetchOwnUserId(actor.page)))
    const [fixedUserId, variableUserId, discretionaryUserId, managerUserId] = userIds

    const fixedStyle = await createWorkStyleViaApi(adminPage, {
      code: `cx15fix_${year}`,
      name: `E2E 混在確認 固定時間制${year}`,
      workTimeSystem: 'fixed',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
    })
    const variableStyle = await createWorkStyleViaApi(adminPage, {
      code: `cx15var_${year}`,
      name: `E2E 混在確認 1か月単位変形労働時間制${year}`,
      workTimeSystem: 'monthly_variable',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      variablePeriodStartDay: 1,
      isShiftBased: true,
    })
    const discretionaryStyle = await createWorkStyleViaApi(adminPage, {
      code: `cx15disc_${year}`,
      name: `E2E 混在確認 裁量労働制${year}`,
      workTimeSystem: 'discretionary',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      deemedDailyMinutes: 480,
      employmentCategoryId: regular?.id,
    })
    const managerStyle = await createWorkStyleViaApi(adminPage, {
      code: `cx15mgr_${year}`,
      name: `E2E 混在確認 管理監督者${year}`,
      workTimeSystem: 'manager_supervisor',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
    })

    await generateShiftAssignments(adminPage, { userId: fixedUserId, workStyleId: fixedStyle.id, from, to })
    await generateShiftAssignments(adminPage, { userId: variableUserId, workStyleId: variableStyle.id, from, to })
    await generateShiftAssignments(adminPage, { userId: discretionaryUserId, workStyleId: discretionaryStyle.id, from, to })
    await generateShiftAssignments(adminPage, { userId: managerUserId, workStyleId: managerStyle.id, from, to })

    const targetDates = weekdaysOfMonth(year, 5).slice(0, 3)

    for (const date of targetDates) {
      await createAttendanceDay(fixedPage, {
        userId: fixedUserId,
        workDate: date,
        actualStartAt: `${date}T09:00:00+09:00`,
        actualEndAt: `${date}T18:00:00+09:00`,
        breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
        reason: '固定時間制の通常勤務(E2E, §5-15)',
      })
      await createAttendanceDay(variablePage, {
        userId: variableUserId,
        workDate: date,
        actualStartAt: `${date}T09:00:00+09:00`,
        actualEndAt: `${date}T18:00:00+09:00`,
        breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
        reason: '変形労働時間制の通常勤務(E2E, §5-15)',
      })
      // 裁量労働制は打刻せずに出勤日を作成しても、みなし時間が計上される(scenario-07参照)。
      await createAttendanceDay(discretionaryPage, {
        userId: discretionaryUserId,
        workDate: date,
        reason: '裁量労働制、打刻なしでの出勤日作成(E2E, §5-15)',
      })
      await createAttendanceDay(managerPage, {
        userId: managerUserId,
        workDate: date,
        actualStartAt: `${date}T09:00:00+09:00`,
        actualEndAt: `${date}T21:00:00+09:00`,
        breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
        reason: '管理監督者の長時間勤務(E2E, §5-15)',
      })
    }

    // 4人分をまとめて「提出済み」にしてから、承認者側の一覧に労働時間制度によらず
    // 全員分表示されることを確認する(承認可否の絞り込みロジックが労働時間制度を
    // 見ていないことの確認)。
    for (const actor of actors) {
      await submitMonth(actor.page, approverId, yearMonth)
    }

    await approverPage.goto('/attendance/months/to-approve')
    for (const userId of userIds) {
      await expect(
        approverPage.getByRole('checkbox', { name: `${yearMonth}(社員ID: ${userId})を選択` }),
      ).toBeVisible()
    }

    // 4件をまとめて選択し、一括承認する(UC-A009)。
    for (const userId of userIds) {
      await approverPage.getByRole('checkbox', { name: `${yearMonth}(社員ID: ${userId})を選択` }).check()
    }
    await approverPage.getByRole('button', { name: 'まとめて承認する' }).click()
    for (const userId of userIds) {
      await expect(
        approverPage.getByRole('checkbox', { name: `${yearMonth}(社員ID: ${userId})を選択` }),
      ).toHaveCount(0)
    }

    // 管理者が労働時間制度によらず全員分を締められることを確認する。
    for (const actor of actors) {
      await closeMonth(adminPage, actor.page, yearMonth)
      expect(await fetchMonthStatus(actor.page, yearMonth)).toBe('closed')
    }
  } finally {
    await fixedContext.close()
    await variableContext.close()
    await discretionaryContext.close()
    await managerContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})

test('§5-16: 月次締め後もバックオフィス処理(交通費精算・名刺申請)は独立して進められる', async ({ browser }) => {
  test.setTimeout(60000)

  const applicantContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const accountingContext = await browser.newContext()
  const generalAffairsContext = await browser.newContext()
  const adminContext = await browser.newContext()

  try {
    const applicantPage = await applicantContext.newPage()
    const approverPage = await approverContext.newPage()
    const accountingPage = await accountingContext.newPage()
    const generalAffairsPage = await generalAffairsContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(accountingPage, SCENARIO_USERS.accountingStaff)
    await loginAs(generalAffairsPage, SCENARIO_USERS.generalAffairsStaff)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    // 当月の勤怠を提出〜承認〜締めまで進める(既に他のシナリオの実行で締め済みなら
    // 冪等にスキップされる、UC-A008〜UC-A011)。
    const { yearMonth } = await submitApproveAndCloseCurrentMonth(applicantPage, approverPage, adminPage)
    expect(await fetchMonthStatus(applicantPage, yearMonth)).toBe('closed')

    // 月次締め後も、同月内に発生した交通費精算の後処理(承認〜経理タスク完了)が
    // 支障なく進められることを確認する(CLAUDE.md「バックオフィス処理は承認とは
    // 別ステータス系列で管理する」の回帰確認)。
    const amount = String(1000 + Math.floor(Math.random() * 8000))
    const expenseTitle = `E2Eテスト§5-16交通費_${amount}`
    await applicantPage.goto('/requests/new')
    await applicantPage.getByLabel('申請種別').selectOption({ label: '交通費精算' })
    await applicantPage.getByLabel('タイトル').fill(expenseTitle)
    await applicantPage.getByLabel('金額').fill(amount)
    await applicantPage.getByLabel('経路').fill('自宅最寄駅→本社最寄駅')
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    await approverPage.goto('/approvals')
    const expenseApprovalRow = approverPage.getByRole('row', { name: expenseTitle })
    await expect(expenseApprovalRow).toBeVisible()
    await expenseApprovalRow.getByRole('link', { name: expenseTitle }).click()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    await accountingPage.goto('/backoffice-tasks')
    const expenseTaskRow = accountingPage.getByRole('row', { name: expenseTitle })
    await expect(expenseTaskRow).toBeVisible()
    await expenseTaskRow.getByRole('link', { name: expenseTitle }).click()
    await pickUser(accountingPage, '担当者', SCENARIO_USERS.accountingStaff, 'makoto.kobayashi@example.com')
    await accountingPage.getByRole('button', { name: '割り当てる' }).click()
    await expect(accountingPage.getByText('未割り当て')).toHaveCount(0)

    // request_types.allowed_status_transitions(経費精算系)により、割り当て時点で自動的に
    // in_reviewになり、そこから直接payment_scheduledへ進む(processingは経由しない)。
    for (const step of [
      { value: 'payment_scheduled', label: '支払予定' },
      { value: 'completed', label: '完了' },
    ]) {
      await accountingPage.getByLabel('状態').selectOption(step.value)
      await accountingPage.getByRole('button', { name: '更新する' }).click()
      await expect(accountingPage.getByRole('status', { name: step.label })).toBeVisible()
    }

    // 同じ月内で名刺申請〜総務タスク完了も独立して進められることを確認する。
    const quantity = String(10 + Math.floor(Math.random() * 90))
    const cardTitle = `E2Eテスト§5-16名刺_${quantity}枚`
    await applicantPage.goto('/requests/new')
    await applicantPage.getByLabel('申請種別').selectOption({ label: '名刺申請' })
    await applicantPage.getByLabel('タイトル').fill(cardTitle)
    await applicantPage.getByLabel('枚数').fill(quantity)
    await pickUser(applicantPage, '承認者', SCENARIO_USERS.approver, 'naoki.watanabe@example.com')
    await applicantPage.getByRole('button', { name: '提出する' }).click()
    await expect(applicantPage.getByRole('heading', { name: cardTitle })).toBeVisible()
    await expect(applicantPage.getByRole('status', { name: '提出済み' })).toBeVisible()

    await approverPage.goto('/approvals')
    const cardApprovalRow = approverPage.getByRole('row', { name: cardTitle })
    await expect(cardApprovalRow).toBeVisible()
    await cardApprovalRow.getByRole('link', { name: cardTitle }).click()
    await approverPage.getByRole('button', { name: '承認する' }).click()
    await expect(approverPage.getByRole('status', { name: '承認済み' })).toBeVisible()

    await generalAffairsPage.goto('/backoffice-tasks')
    const cardTaskRow = generalAffairsPage.getByRole('row', { name: cardTitle })
    await expect(cardTaskRow).toBeVisible()
    await cardTaskRow.getByRole('link', { name: cardTitle }).click()
    await pickUser(generalAffairsPage, '担当者', SCENARIO_USERS.generalAffairsStaff, 'megumi.nakamura@example.com')
    await generalAffairsPage.getByRole('button', { name: '割り当てる' }).click()
    await expect(generalAffairsPage.getByText('未割り当て')).toHaveCount(0)

    // request_types.allowed_status_transitions(名刺申請)により、割り当て時点で自動的に
    // in_reviewになり、そこから直接orderedへ進む(processingは経由しない)。
    for (const step of [
      { value: 'ordered', label: '発注済み' },
      { value: 'shipped', label: '発送済み' },
      { value: 'completed', label: '完了' },
    ]) {
      await generalAffairsPage.getByLabel('状態').selectOption(step.value)
      await generalAffairsPage.getByRole('button', { name: '更新する' }).click()
      await expect(generalAffairsPage.getByRole('status', { name: step.label })).toBeVisible()
    }

    // 一連のバックオフィス処理を経ても、月次締め済みの状態自体には影響していないことを
    // 再確認する(承認とバックオフィス処理は独立したステータス系列であるため)。
    expect(await fetchMonthStatus(applicantPage, yearMonth)).toBe('closed')
  } finally {
    await applicantContext.close()
    await approverContext.close()
    await accountingContext.close()
    await generalAffairsContext.close()
    await adminContext.close()
  }
})
