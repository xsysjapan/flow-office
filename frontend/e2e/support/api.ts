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
        // バックエンドのJSONエラーレスポンスは(JSON_UNESCAPED_UNICODEを付けていないため)
        // 日本語メッセージが`\uXXXX`形式にエスケープされた文字列で返る。そのまま
        // `${text}`で埋め込むと、呼び出し側の`error.message.includes('既に存在します')`
        // のような日本語文字列マッチが常に不一致になってしまうため、JSONとしてデコードした
        // `message`フィールドを優先してエラーメッセージに含める。
        let decodedMessage = text
        try {
          const parsed = JSON.parse(text) as { message?: string }
          if (parsed?.message) decodedMessage = parsed.message
        } catch {
          // JSONとして解釈できない場合は元のテキストのまま扱う。
        }
        throw new Error(`E2E setup: ${method ?? 'GET'} ${path} failed (${response.status}): ${decodedMessage}`)
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

export async function fetchOwnUserId(page: Page): Promise<string> {
  const me = await apiFetch<{ id: string }>(page, '/auth/me')
  return me.id
}

/** 氏名/メールアドレスで社員を検索し、`user_id`を取得する(管理者/人事担当者でログイン済みであること)。 */
export async function fetchUserIdByEmail(page: Page, email: string): Promise<string> {
  const body = await apiFetch<{ data: Array<{ id: string }> }>(page, `/users?q=${encodeURIComponent(email)}`)
  const user = body.data?.[0]
  if (!user) throw new Error(`E2E setup: user not found for ${email}`)
  return user.id
}

/**
 * UC-P003: 有給を申請する(APIを直接叩く版)。scenario-03のUI経由の申請と異なり、
 * 実在しない未来年月(通年運用シミュレーション等)を対象にする場合、画面上の日付入力より
 * 直接APIを叩く方が確実なため用意する。
 */
export async function requestPaidLeave(
  page: Page,
  input: { targetDate: string; leaveType: 'full' | 'am_half' | 'pm_half' | 'hourly'; hours?: number; approverUserId: string; reason?: string },
): Promise<{ id: string }> {
  return apiFetch(page, '/paid-leave/requests', {
    method: 'POST',
    body: {
      target_date: input.targetDate,
      leave_type: input.leaveType,
      hours: input.hours,
      approver_user_id: input.approverUserId,
      reason: input.reason ?? 'E2Eテスト用有給申請',
    },
  })
}

/** UC-P004: 有給申請を承認する(承認者でログイン済みの`page`から呼び出す)。 */
export async function approvePaidLeaveRequest(page: Page, requestId: string): Promise<void> {
  await apiFetch(page, `/paid-leave/requests/${requestId}/approve`, { method: 'POST' })
}

/**
 * 有給を任意の付与日・失効日で付与する(`grantAdditionalPaidLeave`とは異なり、実際の
 * 「今日」基準ではなく任意の年月を対象にした検証(通年運用シミュレーション等)向け)。
 * 呼び出し前に管理者/人事担当者でログイン済みであること。
 */
export async function createPaidLeaveGrant(
  page: Page,
  input: { userId: string; grantedOn: string; expiresOn: string; days: number; reason: string },
): Promise<{ id: string }> {
  return apiFetch(page, '/paid-leave/grants', {
    method: 'POST',
    body: {
      user_id: input.userId,
      granted_on: input.grantedOn,
      expires_on: input.expiresOn,
      granted_days: input.days,
      grant_reason: input.reason,
    },
  })
}

/** UC-P002: 対象社員の有給付与一覧を取得する(管理者/人事担当者限定)。 */
export async function fetchPaidLeaveGrantsForUser(
  page: Page,
  userId: string,
): Promise<Array<{ id: string; granted_on: string; expires_on: string; granted_days: number; used_days: number; remaining_days: number }>> {
  return apiFetch(page, `/paid-leave/grants/user/${userId}`)
}

/** UC-P002: 社員の入社日を設定する(管理者/人事担当者限定)。年次自動付与の起算日になる。 */
export async function setUserHireDate(page: Page, userId: string, hireDate: string): Promise<void> {
  await apiFetch(page, `/users/${userId}/hire-date`, { method: 'PUT', body: { hire_date: hireDate } })
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
  dayId: string,
): Promise<{
  id: string
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

/**
 * UC-C001: 年度カレンダーを作成する。専用の画面(`/admin/work-calendars`)はscenario-00で
 * 確認済みだが、通年運用シミュレーション(scenario-08)のように実在の暦年と衝突しない
 * `fiscal_year`を指定しつつ`starts_on`/`ends_on`は実在の日付にしたい場合、フォーム経由より
 * APIを直接叩く方が確実なため用意する。
 */
export async function createWorkCalendar(
  page: Page,
  input: { name: string; fiscalYear: number; startsOn: string; endsOn: string; weekStartsOn?: number },
): Promise<{ id: string }> {
  return apiFetch(page, '/work-calendars', {
    method: 'POST',
    body: {
      name: input.name,
      fiscal_year: input.fiscalYear,
      starts_on: input.startsOn,
      ends_on: input.endsOn,
      week_starts_on: input.weekStartsOn ?? 1,
    },
  })
}

/** UC-C001: カレンダーの日別設定(休日区分)をまとめて登録する。 */
export async function putWorkCalendarDays(
  page: Page,
  calendarId: string,
  days: Array<{ date: string; day_type: string; is_working_day: boolean; is_legal_holiday: boolean; is_company_holiday: boolean }>,
): Promise<void> {
  await apiFetch(page, `/work-calendars/${calendarId}/days`, { method: 'PUT', body: { days } })
}

/** UC-C001: カレンダーを公開する。公開前は勤務形態から参照できない。 */
export async function publishWorkCalendar(page: Page, calendarId: string): Promise<void> {
  await apiFetch(page, `/work-calendars/${calendarId}/publish`, { method: 'POST' })
}

/** UC-C003: 会社カレンダーの日区分をもとに、指定期間分の勤務予定を一括生成する。 */
export async function generateShiftAssignments(
  page: Page,
  input: { userId: string; workStyleId: string; from: string; to: string },
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
    userId: string
    workDate: string
    actualStartAt?: string
    actualEndAt?: string
    breaks?: Array<{ start: string; end: string }>
    reason: string
  },
): Promise<{ id: string }> {
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
    calendarId?: string
    isShiftBased?: boolean
    legalHolidayRule?: string
    employmentCategoryId?: number
  },
): Promise<{ id: string }> {
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
  assignmentId: string,
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
  userId: string,
  workDate: string,
): Promise<{ id: string } | undefined> {
  const assignments = await apiFetch<Array<{ id: string; work_date: string }>>(
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
  input: { userId: string; weekStartDate: string; designatedDate: string; reason: string },
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
export async function ensureTodayClockedOut(page: Page): Promise<{ dayId: string; workDate: string }> {
  let today = await apiFetch<{ id: string; status: string; work_date: string }>(page, '/attendance/today')

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

type MonthSummary = { id: string; year_month: string; status: string }

/**
 * UC-A008〜UC-A009: 指定した年月の月次勤怠を提出〜承認まで進める(締めまでは行わない)。
 * 同一月に何度実行しても冪等に動くよう、既に進んでいるステータスはスキップする。
 * 呼び出し前に`employeePage`/`approverPage`それぞれで対応するロールでログイン済みであること。
 * `yearMonth`は"today"の月に限らず任意の年月を指定できる(対象日の`attendance_days`が
 * 既に存在している必要がある)。
 */
export async function submitAndApproveMonth(
  employeePage: Page,
  approverPage: Page,
  yearMonth: string,
): Promise<{ monthId: string }> {
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
 * UC-A008〜UC-A011: 当月の月次勤怠を提出〜承認〜締めまで進める。同一日に何度実行しても
 * 冪等に動くよう、既に進んでいるステータスはスキップする(締めた月は二重に締められない
 * ため)。呼び出し前に3つの`page`それぞれで対応するロールでログイン済みであること
 * (社員/承認者/admin・hr_staff)。
 */
export async function submitApproveAndCloseCurrentMonth(
  employeePage: Page,
  approverPage: Page,
  adminPage: Page,
): Promise<{ yearMonth: string; monthId: string; dayId: string; workDate: string }> {
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
 * 対象月の日次内訳・9区分の月合計(`monthly_calculation_totals`、月60時間超残業判定含む)を
 * 取得する。提出前でも都度計算されるため、月次提出を待たずに集計結果を確認できる
 * (`GET /attendance/months/{yearMonth}`)。
 */
export async function fetchAttendanceMonthDetail(
  page: Page,
  yearMonth: string,
): Promise<{
  month: { id: string; status: string } | null
  monthly_calculation_totals: {
    work_minutes: number
    statutory_excess_overtime_minutes: number
    statutory_excess_overtime_within_60h_minutes: number
    statutory_excess_overtime_over_60h_minutes: number
    paid_leave_days: number
  }
}> {
  return apiFetch(page, `/attendance/months/${yearMonth}`)
}

/**
 * UC-A008: 指定した年月の月次勤怠を提出のみ行う(承認は行わない)。`submitAndApproveMonth`と
 * 異なり、複数社員分をまとめて「提出済み」状態にしてから承認者側の一覧・絞り込みを確認したい
 * ケース(§5-15、複数の労働時間制度が混在する月の月次締め)向け。既に提出済み以降のステータス
 * であれば何もしない(冪等)。
 */
export async function submitMonth(employeePage: Page, approverUserId: string, yearMonth: string): Promise<{ monthId: string }> {
  const findMonth = (months: MonthSummary[]) => months.find((m) => m.year_month === yearMonth)

  let months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
  let month = findMonth(months)

  if (!month || month.status === 'not_submitted' || month.status === 'returned') {
    await apiFetch(employeePage, `/attendance/months/${yearMonth}/submit`, {
      method: 'POST',
      body: { approver_user_id: approverUserId },
    })
    months = await apiFetch<MonthSummary[]>(employeePage, '/attendance/months/mine')
    month = findMonth(months)
  }
  if (!month) throw new Error(`E2E setup: month ${yearMonth} not found after submit`)

  return { monthId: month.id }
}

/**
 * UC-A011: 指定した年月の月次勤怠を締める(管理部・admin/hr_staff)。承認済みでなければ
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
