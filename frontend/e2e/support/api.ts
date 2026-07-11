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
