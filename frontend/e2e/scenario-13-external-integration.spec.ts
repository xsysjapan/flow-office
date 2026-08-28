import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  createAutoApprovedExpenseClaim,
  createAttendanceDay,
  createExternalEmployeeMapping,
  createExternalIntegrationConnection,
  fetchOwnUserId,
  fetchUserIdByEmail,
  publishAttendanceExternal,
  publishExpensesExternal,
  submitAndApproveMonth,
} from './support/api'

/**
 * 外部連携(freee/マネーフォワード)の送信フロー確認。
 *
 * 認可コードフローの実UI実装(対象外)は未実装のため、access_token/refresh_token/
 * token_expires_atはAPI直叩き(`ExternalIntegrationConnectionController`拡張分)で投入する。
 * `mock-freee`/`mock-moneyforward`(リポジトリルート、`docker compose up`で起動)へ実際に
 * HTTPリクエストが送られることを、各モックの`/_debug/last-request`で検証する。
 *
 * 前提: `docker compose up`(または`node mock-freee/server.js` / `node mock-moneyforward/server.js`を
 * 個別起動)し、backend の FREEE_ / MF_EXPENSE_ 系環境変数がモックサーバーを指していること
 * (docker-compose.yml の `app` サービスの environment、またはホスト直接起動時は
 * `backend/.env`を書き換える。backend/.env.example参照)。
 */

const MOCK_FREEE_URL = process.env.E2E_MOCK_FREEE_URL ?? 'http://localhost:9001'
const MOCK_MONEYFORWARD_URL = process.env.E2E_MOCK_MONEYFORWARD_URL ?? 'http://localhost:9002'

test('外部連携設定の登録〜freee勤怠送信〜マネーフォワード経費送信', async ({ page, request }) => {
  test.setTimeout(180000)

  // モック側の受信履歴が前回実行分と混ざらないよう、テスト開始時にリセットする。
  await request.post(`${MOCK_FREEE_URL}/_debug/reset`)
  await request.post(`${MOCK_MONEYFORWARD_URL}/_debug/reset`)

  await loginAs(page, SCENARIO_USERS.admin)

  // 1. freee用連携(OAuth2、access_token/refresh_token/token_expires_atを設定)を登録する。
  //    external_office_id はfreee側の事業所ID(company_id)として送信ペイロードに使われる。
  await createExternalIntegrationConnection(page, {
    provider: 'freee',
    name: 'freee本社事業所(E2E)',
    authType: 'oauth2',
    clientId: 'e2e-freee-client-id',
    clientSecret: 'e2e-freee-client-secret',
    accessToken: 'e2e-initial-access-token',
    refreshToken: 'e2e-initial-refresh-token',
    tokenExpiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    externalOfficeId: '999',
    enabled: true,
  })

  // 2. マネーフォワード用連携(APIキー認証)を登録する。
  await createExternalIntegrationConnection(page, {
    provider: 'moneyforward',
    name: 'マネーフォワード本社事業所(E2E)',
    authType: 'api_key',
    apiKey: 'e2e-mf-api-key',
    externalOfficeId: 'office-1',
    enabled: true,
  })

  // 3. 管理画面(ExternalIntegrationConnectionsPage)へ遷移し、両方の連携が一覧に表示され、
  //    有効化トグルがONになっていることを確認する(画面がAPIの状態を実際に反映することの確認)。
  await page.goto('/admin/external-integration-connections')
  const freeeRow = page.getByRole('row', { name: /freee本社事業所\(E2E\)/ })
  const moneyforwardRow = page.getByRole('row', { name: /マネーフォワード本社事業所\(E2E\)/ })
  await expect(freeeRow).toBeVisible()
  await expect(moneyforwardRow).toBeVisible()
  await expect(freeeRow.getByText('有効', { exact: true })).toBeVisible()
  await expect(moneyforwardRow.getByText('有効', { exact: true })).toBeVisible()

  // 4. freee勤怠送信: 月次入力ユーザー(伊藤舞)の当月分を日次実績作成→提出→承認まで進め、
  //    freee向けの従業員番号マッピングを登録してから外部送信APIを呼び出す。
  const monthlyEmployeeUserId = await fetchUserIdByEmail(page, 'mai.ito@example.com')
  await createExternalEmployeeMapping(page, {
    provider: 'freee',
    userId: monthlyEmployeeUserId,
    externalEmployeeCode: '4001',
  })

  const today = new Date().toISOString().slice(0, 10)
  const yearMonth = today.slice(0, 7)

  const employeeContext = await page.context().browser()!.newContext()
  const approverContext = await page.context().browser()!.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()

    await loginAs(employeePage, SCENARIO_USERS.monthlyEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)

    await createAttendanceDay(employeePage, {
      userId: monthlyEmployeeUserId,
      workDate: today,
      actualStartAt: `${today}T09:00:00+09:00`,
      actualEndAt: `${today}T18:00:00+09:00`,
      breaks: [{ start: `${today}T12:00:00+09:00`, end: `${today}T13:00:00+09:00` }],
      reason: 'E2Eテスト用(scenario-13 外部連携送信確認)',
    })

    await submitAndApproveMonth(employeePage, approverPage, yearMonth)
  } finally {
    await employeeContext.close()
    await approverContext.close()
  }

  const attendancePublishResult = await publishAttendanceExternal(page, { yearMonth, provider: 'freee' })
  expect(attendancePublishResult.failures).toEqual([])
  expect(attendancePublishResult.successes).toEqual(
    expect.arrayContaining([expect.objectContaining({ user_id: monthlyEmployeeUserId, year_month: yearMonth })]),
  )

  const freeeLastRequest = await (await request.get(`${MOCK_FREEE_URL}/_debug/last-request`)).json()
  const [expectedYear, expectedMonth] = yearMonth.split('-')
  expect(freeeLastRequest.lastRequest.method).toBe('PUT')
  expect(freeeLastRequest.lastRequest.path).toBe(
    `/hr/api/v1/employees/4001/work_record_summaries/${expectedYear}/${Number(expectedMonth)}`,
  )
  expect(freeeLastRequest.lastRequest.headers.authorization).toBe('Bearer e2e-initial-access-token')
  expect(freeeLastRequest.lastRequest.body).toMatchObject({ company_id: 999 })

  // 5. マネーフォワード経費送信: 承認スキップ閾値以下の交通費(自動承認)を作成し、
  //    マネーフォワード向けの従業員番号マッピングを登録してから外部送信APIを呼び出す。
  const adminUserId = await fetchOwnUserId(page)
  await createExternalEmployeeMapping(page, {
    provider: 'moneyforward',
    userId: adminUserId,
    externalEmployeeCode: 'member-e2e-1',
  })

  await createAutoApprovedExpenseClaim(page, {
    categoryCode: 'transportation',
    amount: 500,
    usageDate: today,
    description: 'E2Eテスト用(scenario-13 外部連携送信確認)',
    approverUserId: adminUserId,
  })

  const expensePublishResult = await publishExpensesExternal(page, { yearMonth, provider: 'moneyforward' })
  expect(expensePublishResult.failures).toEqual([])
  expect(expensePublishResult.successes).toEqual(
    expect.arrayContaining([expect.objectContaining({ employee_id: adminUserId })]),
  )

  const mfLastRequest = await (await request.get(`${MOCK_MONEYFORWARD_URL}/_debug/last-request`)).json()
  expect(mfLastRequest.lastRequest.method).toBe('POST')
  expect(mfLastRequest.lastRequest.path).toBe(
    '/api/external/v1/offices/office-1/office_members/member-e2e-1/ex_transactions',
  )
  expect(mfLastRequest.lastRequest.headers['x-api-key']).toBe('e2e-mf-api-key')
})
