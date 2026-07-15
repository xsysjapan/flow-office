import type { Page } from '@playwright/test'

const API_BASE_URL = process.env.E2E_API_BASE_URL ?? 'http://localhost:8000/api'

/**
 * `page`にログイン済みのSanctumトークンでAPIを直接叩く共通ヘルパー。画面がまだ無い/
 * 繰り返し実行のための前提データ調整用(このファイルの他関数と同じ用途)。
 */
async function apiFetch<T>(page: Page, path: string, init?: { method?: string; body?: unknown }): Promise<T> {
  return page.evaluate(
    async ({ apiBase, path, method, body }) => {
      const token = localStorage.getItem('flow-office.token')
      const response = await fetch(`${apiBase}${path}`, {
        method: method ?? 'GET',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
          ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
      })
      const text = await response.text()
      if (!response.ok) {
        throw new Error(`E2E setup: ${method ?? 'GET'} ${path} failed (${response.status}): ${text}`)
      }
      return text ? JSON.parse(text) : null
    },
    { apiBase: API_BASE_URL, path, method: init?.method, body: init?.body },
  )
}

/**
 * E2Eテストを何度も実行すると、有給消化シナリオなどでシード時点の付与日数
 * (ScenarioSeederが1回だけ付与する10日)を使い切ってしまう。テストの前提を
 * 満たすため、管理者としてログイン中の`page`を使って対象社員に有給を追加付与する。
 *
 * 呼び出し前に `loginAs(page, SCENARIO_USERS.admin)` 済みであること。
 * (admin/hr_staffのSanctumトークンがlocalStorageに入っている前提)
 */
export async function grantAdditionalPaidLeave(page: Page, email: string, days: number): Promise<void> {
  await page.evaluate(
    async ({ apiBase, email, days }) => {
      const token = localStorage.getItem('flow-office.token')
      const headers = {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      }

      const usersResponse = await fetch(`${apiBase}/users?q=${encodeURIComponent(email)}`, { headers })
      const usersBody = await usersResponse.json()
      const user = usersBody.data?.[0]
      if (!user) throw new Error(`E2E setup: user not found for ${email}`)

      const today = new Date().toISOString().slice(0, 10)
      const expiresOn = `${Number(today.slice(0, 4)) + 2}-03-31`

      const grantResponse = await fetch(`${apiBase}/paid-leave/grants`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          user_id: user.id,
          granted_on: today,
          expires_on: expiresOn,
          granted_days: days,
          grant_reason: 'E2Eテスト用追加付与(scenario-03)',
        }),
      })
      if (!grantResponse.ok) {
        throw new Error(`E2E setup: failed to grant paid leave (${grantResponse.status})`)
      }
    },
    { apiBase: API_BASE_URL, email, days },
  )
}

/**
 * 経費CSV出力(UC-E001, GET /exports/expenses)の画面がまだ無いため、APIを直接叩いて
 * 確認する(docs/testing/scenario-tests.md シナリオ4参照)。呼び出し前に
 * accounting_staff/adminでログイン済みであること。
 */
export async function fetchExpensesCsv(page: Page, from: string, to: string): Promise<string> {
  return page.evaluate(
    async ({ apiBase, from, to }) => {
      const token = localStorage.getItem('flow-office.token')
      const response = await fetch(`${apiBase}/exports/expenses?from=${from}&to=${to}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!response.ok) {
        throw new Error(`E2E: failed to fetch expenses CSV (${response.status})`)
      }
      return response.text()
    },
    { apiBase: API_BASE_URL, from, to },
  )
}

export async function fetchOwnUserId(page: Page): Promise<number> {
  const me = await apiFetch<{ id: number }>(page, '/auth/me')
  return me.id
}

/**
 * UC-A012: 打刻ログを記録する。専用の打刻端末/画面がまだ無いため、APIを直接叩く。
 * 打刻ログは矛盾があっても常に記録される(1日分の勤務として組み立てられる場合のみ
 * `attendance_days` に反映される)。
 */
export async function recordAttendancePunch(
  page: Page,
  input: { workDate: string; punchType: 'clock_in' | 'break_start' | 'break_end' | 'clock_out'; punchedAt: string },
): Promise<void> {
  await apiFetch(page, '/attendance-punches', {
    method: 'POST',
    body: {
      work_date: input.workDate,
      punch_type: input.punchType,
      punched_at: input.punchedAt,
      source: 'e2e_test_device',
    },
  })
}

export async function fetchAttendancePunches(page: Page, from: string, to: string): Promise<unknown[]> {
  return apiFetch(page, `/attendance-punches?from=${from}&to=${to}`)
}

/** 日次勤怠の詳細(calculation含む)を取得する。UI未表示の内訳項目をAPIで直接確認する用途。 */
export async function fetchAttendanceDay(
  page: Page,
  dayId: number,
): Promise<{
  id: number
  actual_start_at: string | null
  actual_end_at: string | null
  calculation: {
    work_minutes: number
    deemed_work_minutes: number | null
    payroll_work_minutes: number
    statutory_within_overtime_minutes: number
    statutory_excess_overtime_minutes: number
    late_night_work_minutes: number
    legal_holiday_work_minutes: number
    prescribed_holiday_work_minutes: number
    late_night_legal_holiday_work_minutes: number
  } | null
}> {
  return apiFetch(page, `/attendance/days/${dayId}`)
}

export async function fetchEmploymentCategories(page: Page): Promise<Array<{ id: number; code: string; name: string }>> {
  return apiFetch(page, '/employment-categories')
}

/** UC-C003: 会社カレンダーの日区分をもとに、指定期間分の勤務予定を一括生成する。 */
export async function generateShiftAssignments(
  page: Page,
  input: { userId: number; workStyleId: number; from: string; to: string },
): Promise<void> {
  await apiFetch(page, '/employee-shift-assignments/generate', {
    method: 'POST',
    body: { user_id: input.userId, work_style_id: input.workStyleId, from: input.from, to: input.to },
  })
}

/**
 * UC-A016: 出勤日(attendance_days)を任意の勤務日に新規作成する。打刻していない日にも
 * 実績を入力できるようにするAPI(2026-07-12追加)。専用の画面がまだ無いため
 * APIを直接叩く(scenario-02参照)。
 */
export async function createAttendanceDay(
  page: Page,
  input: {
    userId: number
    workDate: string
    actualStartAt?: string
    actualEndAt?: string
    breaks?: Array<{ start: string; end: string }>
    reason: string
  },
): Promise<{ id: number }> {
  return apiFetch(page, '/attendance/days', {
    method: 'POST',
    body: {
      user_id: input.userId,
      work_date: input.workDate,
      actual_start_at: input.actualStartAt,
      actual_end_at: input.actualEndAt,
      breaks: input.breaks ?? [],
      reason: input.reason,
    },
  })
}

/**
 * UC-C002: 勤務形態を作成する。1か月単位変形労働時間制・裁量労働制・管理監督者・
 * 法定休日「決めない方式」など、管理画面のフォームにまだ項目が無い設定
 * (employment_category_id/deemed_daily_minutes/variable_period_start_day/
 * legal_holiday_rule=undetermined)はAPIを直接叩いて作成する(scenario-07参照)。
 */
export async function createWorkStyleViaApi(
  page: Page,
  input: {
    code: string
    name: string
    workTimeSystem: string
    prescribedDailyMinutes: number
    prescribedWeeklyMinutes: number
    deemedDailyMinutes?: number
    variablePeriodStartDay?: number
    calendarId?: number
    isShiftBased?: boolean
    legalHolidayRule?: string
    employmentCategoryId?: number
  },
): Promise<{ id: number }> {
  return apiFetch(page, '/work-styles', {
    method: 'POST',
    body: {
      code: input.code,
      name: input.name,
      work_time_system: input.workTimeSystem,
      prescribed_daily_minutes: input.prescribedDailyMinutes,
      prescribed_weekly_minutes: input.prescribedWeeklyMinutes,
      deemed_daily_minutes: input.deemedDailyMinutes,
      variable_period_start_day: input.variablePeriodStartDay,
      calendar_id: input.calendarId,
      is_shift_based: input.isShiftBased ?? false,
      legal_holiday_rule: input.legalHolidayRule,
      employment_category_id: input.employmentCategoryId,
    },
  })
}

/**
 * UC-C006: 1か月単位変形労働時間制の所定労働時間を編集する。専用の画面がまだ無いため
 * APIを直接叩く(scenario-07参照)。
 */
export async function editEmployeeShiftAssignment(
  page: Page,
  assignmentId: number,
  input: { plannedStartAt?: string; plannedEndAt?: string; plannedBreakMinutes: number; reason: string },
): Promise<void> {
  await apiFetch(page, `/employee-shift-assignments/${assignmentId}`, {
    method: 'PUT',
    body: {
      planned_start_at: input.plannedStartAt,
      planned_end_at: input.plannedEndAt,
      planned_break_minutes: input.plannedBreakMinutes,
      reason: input.reason,
    },
  })
}

export async function fetchShiftAssignment(
  page: Page,
  userId: number,
  workDate: string,
): Promise<{ id: number } | undefined> {
  const assignments = await apiFetch<Array<{ id: number; work_date: string }>>(
    page,
    `/employee-shift-assignments?user_id=${userId}&from=${workDate}&to=${workDate}`,
  )
  return assignments[0]
}

/**
 * UC-C007: 法定休日「決めない方式」の週の法定休日を指定する。専用の画面がまだ無いため
 * APIを直接叩く(scenario-07参照)。
 */
export async function designateLegalHoliday(
  page: Page,
  input: { userId: number; weekStartDate: string; designatedDate: string; reason: string },
): Promise<void> {
  await apiFetch(page, '/attendance/legal-holiday-designations', {
    method: 'POST',
    body: {
      user_id: input.userId,
      week_start_date: input.weekStartDate,
      designated_date: input.designatedDate,
      reason: input.reason,
    },
  })
}

/**
 * 当日の勤怠(`attendance_days`)が`clocked_out`になっていることを保証する。
 * 打刻は同じ日に2回出勤できない設計のため(scenario-01参照)、既に出勤・退勤済みなら
 * 何もしない冪等な実装にする。
 */
export async function ensureTodayClockedOut(page: Page): Promise<{ dayId: number; workDate: string }> {
  let today = await apiFetch<{ id: number; status: string; work_date: string }>(page, '/attendance/today')

  if (today.status === 'not_started') {
    await apiFetch(page, '/attendance/clock-in', { method: 'POST' })
    today = await apiFetch(page, '/attendance/today')
  }
  if (today.status === 'working') {
    await apiFetch(page, '/attendance/clock-out', { method: 'POST' })
    today = await apiFetch(page, '/attendance/today')
  }
  if (today.status !== 'clocked_out') {
    throw new Error(`E2E setup: unexpected attendance status ${today.status}`)
  }

  return { dayId: today.id, workDate: today.work_date }
}

type MonthSummary = { id: number; year_month: string; status: string }

/**
 * UC-A008〜UC-A009: 指定した年月の勤怠月次を提出〜承認まで進める(締めまでは行わない)。
 * 同一月に何度実行しても冪等に動くよう、既に進んでいるステータスはスキップする。
 * 呼び出し前に`employeePage`/`approverPage`それぞれで対応するロールでログイン済みであること。
 * `yearMonth`は"today"の月に限らず任意の年月を指定できる(対象日の`attendance_days`が
 * 既に存在している必要がある)。
 */
export async function submitAndApproveMonth(
  employeePage: Page,
  approverPage: Page,
  yearMonth: string,
): Promise<{ monthId: number }> {
  const approverId = await fetchOwnUserId(approverPage)
  const findMonth = (months: MonthSummary[]) => months.find((m) => m.year_month === yearMonth)

  let months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
  let month = findMonth(months)

  if (!month || month.status === 'not_submitted' || month.status === 'returned') {
    await apiFetch(employeePage, `/attendance/months/${yearMonth}/submit`, {
      method: 'POST',
      body: { approver_user_id: approverId },
    })
    months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
    month = findMonth(months)
  }
  if (!month) throw new Error(`E2E setup: month ${yearMonth} not found after submit`)

  if (month.status === 'submitted') {
    await apiFetch(approverPage, `/attendance-months/${month.id}/approve`, { method: 'POST' })
  }

  return { monthId: month.id }
}

/**
 * UC-A008〜UC-A011: 当月の勤怠月次を提出〜承認〜締めまで進める。同一日に何度実行しても
 * 冪等に動くよう、既に進んでいるステータスはスキップする(締めた月は二重に締められない
 * ため)。呼び出し前に3つの`page`それぞれで対応するロールでログイン済みであること
 * (社員/承認者/admin・hr_staff)。
 */
export async function submitApproveAndCloseCurrentMonth(
  employeePage: Page,
  approverPage: Page,
  adminPage: Page,
): Promise<{ yearMonth: string; monthId: number; dayId: number; workDate: string }> {
  const { dayId, workDate } = await ensureTodayClockedOut(employeePage)
  const yearMonth = workDate.slice(0, 7)

  const { monthId } = await submitAndApproveMonth(employeePage, approverPage, yearMonth)

  const months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
  const month = months.find((m) => m.id === monthId)
  if (month?.status === 'approved') {
    await apiFetch(adminPage, `/attendance-months/${monthId}/close`, { method: 'POST' })
  }

  return { yearMonth, monthId, dayId, workDate }
}

export async function fetchMonthStatus(page: Page, yearMonth: string): Promise<string | undefined> {
  const months = await apiFetch<MonthSummary[]>(page, '/attendance/months/mine')
  return months.find((m) => m.year_month === yearMonth)?.status
}

/**
 * UC-A011: 指定した年月の勤怠月次を締める(管理部・admin/hr_staff)。承認済みでなければ
 * 何もしない(冪等)。
 */
export async function closeMonth(adminPage: Page, employeePage: Page, yearMonth: string): Promise<void> {
  const status = await fetchMonthStatus(employeePage, yearMonth)
  if (status !== 'approved') return

  const months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
  const month = months.find((m) => m.year_month === yearMonth)
  if (!month) throw new Error(`E2E setup: month ${yearMonth} not found`)

  await apiFetch(adminPage, `/attendance-months/${month.id}/close`, { method: 'POST' })
}
