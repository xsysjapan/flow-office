import type { Page } from '@playwright/test'

const API_BASE_URL = process.env.E2E_API_BASE_URL ?? 'http://localhost:8000/api'

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
