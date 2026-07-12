import { expect, test, type Browser, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  createAttendanceDay,
  createWorkStyleViaApi,
  designateLegalHoliday,
  editEmployeeShiftAssignment,
  fetchAttendanceDay,
  fetchEmploymentCategories,
  fetchOwnUserId,
  fetchShiftAssignment,
  generateShiftAssignments,
} from './support/api'

/**
 * 追加の勤務形態(1か月単位変形労働時間制・裁量労働制・管理監督者・法定休日「決めない
 * 方式」)のシナリオ。docs/testing/scenario-tests.md §5(その他のシナリオ)に相当する
 * 追加分として、docs/08-usecases-calendar-shift.md UC-C002・UC-C006・UC-C007、
 * docs/07-usecases-attendance.md「裁量労働制・管理監督者」に対応する。
 *
 * 対象社員には`docs/testing/scenario-tests.md`§3で予備枠とされている
 * mock-entra-user-001〜003(山田太郎・佐藤花子・鈴木一郎)を使う。勤務形態・シフト生成・
 * 所定編集は管理者(admin)が行い(UC-C002/UC-C003/UC-C006はrole:admin,hr_staff限定)、
 * 出勤日の作成・法定休日指定は本人または管理者が行える(UC-A016/UC-C007)ため、いずれも
 * 管理者ページから対象社員のuser_idを指定して実行する。
 *
 * 実在の年月と衝突しないよう、ランダムな未来年度(7000年台)の日付を使う
 * (scenario-00と同じ考え方)。このため週次勤怠画面を現在週から辿って対象日まで
 * 移動することは現実的ではなく、計算結果の確認はAPIを直接叩いて行う
 * (`fetchAttendanceDay`。§5-4等と同じハイブリッド方針)。
 *
 * 【既知のフロントエンド未対応事項】管理画面の勤務形態作成フォーム
 * (`WorkStylesAndShiftsPage`)には、雇用区分・みなし時間(deemed_daily_minutes)・
 * 変形期間の起算日(variable_period_start_day)・法定休日「決めない方式」の入力欄がまだ
 * 無く、`calendar_id`も必須のままになっている(バックエンドは2026-07-12時点でnullable)。
 * また週次勤怠画面(`WeekAttendancePage`)は実働時間(actual_work_minutes)しか表示せず、
 * 給与計算上の労働時間(payroll_work_minutes)・みなし時間(deemed_work_minutes)・
 * 法定内/法定時間外・法定休日労働などの内訳を表示しない。加えて「打刻漏れ」警告は
 * `day.status !== 'clocked_out'`のみで判定しており、裁量労働制のように打刻自体が
 * 不要な勤務形態を考慮していない(実際には出退勤していないのに警告が出てしまう)。
 * 法定休日「決めない方式」の指定操作、1か月単位変形労働時間制の所定編集にも専用画面が
 * まだ無い。これらのフロントエンド対応は本シナリオでは対象外とする。
 */

const FISCAL_YEAR_BASE = 7000 + Math.floor(Math.random() * 1000)

/**
 * 対象社員としていったんログインしてuser_id確定(初回ログインならユーザー作成も兼ねる)
 * させたうえで、以降のマスタ設定操作は管理者ページから行えるようにする。
 */
async function setUpEmployeeAndAdmin(
  browser: Browser,
  employeeName: string,
): Promise<{ employeePage: Page; adminPage: Page; userId: number; close: () => Promise<void> }> {
  const employeeContext = await browser.newContext()
  const adminContext = await browser.newContext()
  const employeePage = await employeeContext.newPage()
  const adminPage = await adminContext.newPage()

  await loginAs(employeePage, employeeName)
  await loginAs(adminPage, SCENARIO_USERS.admin)
  const userId = await fetchOwnUserId(employeePage)

  return {
    employeePage,
    adminPage,
    userId,
    close: async () => {
      await employeeContext.close()
      await adminContext.close()
    },
  }
}

test('1か月単位変形労働時間制: 所定を超えて設定した日は、所定を超えた分のみ法定時間外になる', async ({ browser }) => {
  test.setTimeout(60000)
  const { adminPage, userId, close } = await setUpEmployeeAndAdmin(browser, '山田 太郎')
  try {
    const year = FISCAL_YEAR_BASE
    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `mv_${year}`,
      name: `E2E 1か月単位変形労働時間制${year}`,
      workTimeSystem: 'monthly_variable',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      variablePeriodStartDay: 1,
      isShiftBased: true,
    })

    const from = `${year}-01-01`
    const to = `${year}-01-10`
    await generateShiftAssignments(adminPage, { userId, workStyleId: workStyle.id, from, to })

    // カレンダー無しでの生成のため所定は09:00-18:00(8時間)一律。1日だけ、あらかじめ
    // 10時間(09:00-20:00)の所定労働時間を設定する(UC-C006)。
    const targetDate = `${year}-01-05`
    const targetAssignment = await fetchShiftAssignment(adminPage, userId, targetDate)
    if (!targetAssignment) throw new Error('E2E setup: shift assignment not found')

    await editEmployeeShiftAssignment(adminPage, targetAssignment.id, {
      plannedStartAt: `${targetDate}T09:00:00+09:00`,
      plannedEndAt: `${targetDate}T20:00:00+09:00`,
      plannedBreakMinutes: 60,
      reason: 'あらかじめ10時間の所定労働時間を設定する(E2E)',
    })

    // 実際に11時間(09:00-21:00)働く。所定(10時間)を超えた1時間だけが法定時間外になる
    // (固定時間制なら8時間を超えた3時間が法定時間外になってしまうところ)。
    const day = await createAttendanceDay(adminPage, {
      userId,
      workDate: targetDate,
      actualStartAt: `${targetDate}T09:00:00+09:00`,
      actualEndAt: `${targetDate}T21:00:00+09:00`,
      breaks: [{ start: `${targetDate}T12:00:00+09:00`, end: `${targetDate}T13:00:00+09:00` }],
      reason: 'あらかじめ設定した所定を1時間超える勤務(E2E)',
    })

    const detail = await fetchAttendanceDay(adminPage, day.id)
    expect(detail.calculation?.actual_work_minutes).toBe(660)
    expect(detail.calculation?.statutory_overtime_minutes).toBe(60)

    // 既に勤務実績がある日は、事後にシフト予定を変更できない(既に発生した時間外労働を
    // 通常勤務へ振り替えることを防ぐガード)。
    await expect(
      editEmployeeShiftAssignment(adminPage, targetAssignment.id, {
        plannedStartAt: `${targetDate}T09:00:00+09:00`,
        plannedEndAt: `${targetDate}T22:00:00+09:00`,
        plannedBreakMinutes: 60,
        reason: '事後に所定を伸ばして残業を消そうとする変更(拒否されるべき、E2E)',
      }),
    ).rejects.toThrow()
  } finally {
    await close()
  }
})

test('裁量労働制: 雇用区分「正社員」と組み合わせ、打刻せずにみなし時間で給与計算される', async ({ browser }) => {
  test.setTimeout(60000)
  const { adminPage, userId, close } = await setUpEmployeeAndAdmin(browser, '佐藤 花子')
  try {
    const employmentCategories = await fetchEmploymentCategories(adminPage)
    const regular = employmentCategories.find((c) => c.code === 'regular')

    const year = FISCAL_YEAR_BASE
    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `disc_${year}`,
      name: `E2E 裁量労働制${year}`,
      workTimeSystem: 'discretionary',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      deemedDailyMinutes: 540, // みなし9時間
      employmentCategoryId: regular?.id,
    })

    const workDate = `${year}-02-03`
    await generateShiftAssignments(adminPage, { userId, workStyleId: workStyle.id, from: workDate, to: workDate })

    // 出退勤の打刻を一切せず(UC-A016で作成する出勤日にactual_start_at/actual_end_atを
    // 設定しない)、それでもみなし時間が給与計算上の労働時間として計上されることを確認する。
    const day = await createAttendanceDay(adminPage, {
      userId,
      workDate,
      reason: '裁量労働制、打刻なしでの出勤日作成(E2E)',
    })

    const detail = await fetchAttendanceDay(adminPage, day.id)
    expect(detail.actual_start_at).toBeNull()
    expect(detail.calculation?.actual_work_minutes).toBe(0)
    expect(detail.calculation?.deemed_work_minutes).toBe(540)
    expect(detail.calculation?.payroll_work_minutes).toBe(540)
    expect(detail.calculation?.statutory_overtime_minutes).toBe(60) // みなし9時間のうち8時間超の1時間
  } finally {
    await close()
  }
})

test('管理監督者: 長時間勤務でも残業として計上されない', async ({ browser }) => {
  test.setTimeout(60000)
  const { adminPage, userId, close } = await setUpEmployeeAndAdmin(browser, '鈴木 一郎')
  try {
    const year = FISCAL_YEAR_BASE
    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `mgr_${year}`,
      name: `E2E 管理監督者${year}`,
      workTimeSystem: 'manager_supervisor',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
    })

    const workDate = `${year}-03-05`
    await generateShiftAssignments(adminPage, { userId, workStyleId: workStyle.id, from: workDate, to: workDate })

    // 09:00〜21:00(休憩1時間、実働11時間)という長時間勤務でも、管理監督者は労働時間規定の
    // 適用が除外されるため残業として計上されない。
    const day = await createAttendanceDay(adminPage, {
      userId,
      workDate,
      actualStartAt: `${workDate}T09:00:00+09:00`,
      actualEndAt: `${workDate}T21:00:00+09:00`,
      breaks: [{ start: `${workDate}T12:00:00+09:00`, end: `${workDate}T13:00:00+09:00` }],
      reason: '管理監督者の長時間勤務(E2E)',
    })

    const detail = await fetchAttendanceDay(adminPage, day.id)
    expect(detail.calculation?.actual_work_minutes).toBe(660)
    expect(detail.calculation?.payroll_work_minutes).toBe(660)
    expect(detail.calculation?.statutory_overtime_minutes).toBe(0)
    expect(detail.calculation?.non_statutory_overtime_minutes).toBe(0)
  } finally {
    await close()
  }
})

test('法定休日「決めない方式」: 自動推定した休日と、指定による上書きが反映される', async ({ browser }) => {
  test.setTimeout(60000)
  const { adminPage, userId, close } = await setUpEmployeeAndAdmin(browser, '山田 太郎')
  try {
    const year = FISCAL_YEAR_BASE + 1 // 他のテストの勤務形態コード・年度と衝突しないよう1年ずらす

    // LegalHolidayResolverは月曜始まり(week_starts_on=1)の実週で自動推定・指定を解決するため、
    // 年によって4/6等の固定日付が月曜とは限らない。4月中旬を起点に、実際に月曜始まりの週を
    // 動的に求める(週全体が年度内に収まるよう4月15日を起点にする)。
    const weekStartDate = mondayOnOrBefore(new Date(Date.UTC(year, 3, 15)))
    const weekEndDate = addDaysUtc(weekStartDate, 6)
    const weekStart = formatDateUtc(weekStartDate)
    const weekEnd = formatDateUtc(weekEndDate)
    const restDate = weekEnd // 週内で唯一の休み(日曜、週の最終日)
    const overrideDate = formatDateUtc(addDaysUtc(weekStartDate, 2)) // 水曜

    // カレンダーで対象週の日曜だけ休みにし、それ以外は稼働日にする
    // (シフト一括生成はカレンダーの日区分をそのまま反映するため)。
    const calendar = await createCalendarWithOneRestDay(adminPage, year, restDate)

    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `ud_${year}`,
      name: `E2E 法定休日決めない方式${year}`,
      workTimeSystem: 'fixed',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      calendarId: calendar.id,
      isShiftBased: true,
      legalHolidayRule: 'undetermined',
    })

    await generateShiftAssignments(adminPage, { userId, workStyleId: workStyle.id, from: weekStart, to: weekEnd })

    const restAssignment = await fetchShiftAssignment(adminPage, userId, restDate)
    expect(restAssignment).toBeTruthy()

    // 唯一の休みの日に出勤する(自動推定でこの日が法定休日とみなされる)。
    const restDay = await createAttendanceDay(adminPage, {
      userId,
      workDate: restDate,
      actualStartAt: `${restDate}T10:00:00+09:00`,
      actualEndAt: `${restDate}T14:00:00+09:00`,
      reason: '自動推定された法定休日への出勤(E2E)',
    })
    let restDetail = await fetchAttendanceDay(adminPage, restDay.id)
    expect(restDetail.calculation?.legal_holiday_work_minutes).toBe(240)

    // 管理者が、週の途中の平日を法定休日として指定し直す。
    await designateLegalHoliday(adminPage, {
      userId,
      weekStartDate: weekStart,
      designatedDate: overrideDate,
      reason: '水曜を法定休日として指定する(E2E)',
    })

    // 指定により、既存の日曜出勤の計算が再実行され、法定休日ではなくなる。
    restDetail = await fetchAttendanceDay(adminPage, restDay.id)
    expect(restDetail.calculation?.legal_holiday_work_minutes).toBe(0)

    // 指定した水曜に出勤すると、今度はそちらが法定休日として計上される。
    const overrideAssignment = await fetchShiftAssignment(adminPage, userId, overrideDate)
    expect(overrideAssignment).toBeTruthy()
    const overrideDay = await createAttendanceDay(adminPage, {
      userId,
      workDate: overrideDate,
      actualStartAt: `${overrideDate}T09:00:00+09:00`,
      actualEndAt: `${overrideDate}T18:00:00+09:00`,
      breaks: [{ start: `${overrideDate}T12:00:00+09:00`, end: `${overrideDate}T13:00:00+09:00` }],
      reason: '指定した法定休日への出勤(E2E)',
    })
    const overrideDetail = await fetchAttendanceDay(adminPage, overrideDay.id)
    expect(overrideDetail.calculation?.legal_holiday_work_minutes).toBe(480)
  } finally {
    await close()
  }
})

/** 月曜始まり(ISO週)で、指定日を含む週の月曜日を返す。 */
function mondayOnOrBefore(date: Date): Date {
  const dow = date.getUTCDay() // 0=日, 1=月, ..., 6=土
  const diff = dow === 0 ? 6 : dow - 1
  return addDaysUtc(date, -diff)
}

function addDaysUtc(date: Date, days: number): Date {
  const d = new Date(date)
  d.setUTCDate(d.getUTCDate() + days)
  return d
}

function formatDateUtc(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/** 法定休日「決めない方式」シナリオ用: 対象週の指定日(日曜)だけ休みにしたカレンダーを作る。 */
async function createCalendarWithOneRestDay(page: Page, year: number, restDate: string): Promise<{ id: number }> {
  const apiBase = process.env.E2E_API_BASE_URL ?? 'http://localhost:8000/api'

  return page.evaluate(
    async ({ apiBase, year, restDate }) => {
      const token = localStorage.getItem('flow-office.token')
      const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }

      const createResponse = await fetch(`${apiBase}/work-calendars`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: `E2E 決めない方式用カレンダー${year}`,
          fiscal_year: year,
          starts_on: `${year}-04-01`,
          ends_on: `${year + 1}-03-31`,
          week_starts_on: 1,
        }),
      })
      if (!createResponse.ok) throw new Error(`E2E setup: create calendar failed (${createResponse.status})`)
      const calendar = await createResponse.json()

      const daysResponse = await fetch(`${apiBase}/work-calendars/${calendar.id}/days`, {
        method: 'PUT',
        headers,
        body: JSON.stringify({
          days: [
            {
              date: restDate,
              day_type: 'legal_holiday',
              is_working_day: false,
              is_legal_holiday: false,
              is_company_holiday: false,
            },
          ],
        }),
      })
      if (!daysResponse.ok) throw new Error(`E2E setup: set calendar days failed (${daysResponse.status})`)

      const publishResponse = await fetch(`${apiBase}/work-calendars/${calendar.id}/publish`, { method: 'POST', headers })
      if (!publishResponse.ok) throw new Error(`E2E setup: publish calendar failed (${publishResponse.status})`)

      return calendar
    },
    { apiBase, year, restDate },
  )
}
