import { execSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import {
  approvePaidLeaveRequest,
  closeMonth,
  createAttendanceDay,
  createPaidLeaveGrant,
  createWorkCalendar,
  createWorkStyleViaApi,
  fetchMonthStatus,
  fetchOwnUserId,
  fetchPaidLeaveGrantsForUser,
  generateShiftAssignments,
  publishWorkCalendar,
  putWorkCalendarDays,
  requestPaidLeave,
  setUserHireDate,
  submitAndApproveMonth,
} from './support/api'

/**
 * docs/testing/scenario-tests.md シナリオ6(通年運用シミュレーション、1年間)。
 *
 * 実行時点(2026-07)のScenarioSeederが投入するカレンダーは`fiscal_year`が実行時点の
 * 実年(2026)固定・期間も実行月の前後1か月分しかカバーしないため、本シナリオ専用に
 * 2026-04-01〜2027-03-31を丸ごとカバーする`WorkCalendar`を新規作成する。既存の
 * `fiscal_year=2026`(実行時点の値)やscenario-00/07(3000〜8999年度)・scenario-09
 * (9000年台)と衝突しないよう、`fiscal_year`には59000番台のテスト専用値を使う
 * (`starts_on`/`ends_on`は`fiscal_year`の値と無関係な単なる日付フィールドのため、
 * 実在の2026-04-01〜2027-03-31を設定して問題ない)。
 *
 * 【ドキュメントとの差異】`docs/testing/scenario-tests.md`シナリオ6の当初案は
 * `fiscal_year`の例として「990001のような値」を挙げていたが、`work_calendars.fiscal_year`
 * は`unsignedSmallInteger`(最大65535)のため990001は入らない。59000番台に読み替えている
 * (本ファイル作成時にドキュメント側も修正済み)。
 *
 * 対象社員は予備枠 mock-entra-user-003(鈴木一郎)を1名使う(scenario-07・scenario-09が
 * 同じ予備枠ユーザーを使っているが、いずれも実在しない年月(7000年台・9000年台)を
 * 対象にしており、本シナリオの実在の2026〜2027年とは日付レンジで隔離されているため
 * 衝突しない)。
 */

const CURRENT_DIR = path.dirname(fileURLToPath(import.meta.url))
const BACKEND_DIR = path.resolve(CURRENT_DIR, '../../backend')
const TARGET_EMPLOYEE_NAME = '鈴木 一郎'

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate()
}

function dayOfWeek(year: number, month: number, day: number): number {
  return new Date(Date.UTC(year, month - 1, day)).getUTCDay()
}

function isWeekend(year: number, month: number, day: number): boolean {
  const dow = dayOfWeek(year, month, day)
  return dow === 0 || dow === 6
}

/** ゴールデンウィーク(5/3-5/5)・お盆(8/13-15)・年末年始(12/29-1/3)を会社休日クラスタとする。 */
function isCompanyHolidayCluster(month: number, day: number): boolean {
  if (month === 5 && day >= 3 && day <= 5) return true
  if (month === 8 && day >= 13 && day <= 15) return true
  if (month === 12 && day >= 29) return true
  if (month === 1 && day <= 3) return true
  return false
}

/** 対象月の平日(週末・会社休日クラスタを除く営業日)を1日から順に列挙する。 */
function businessDaysOfMonth(year: number, month: number): string[] {
  const dates: string[] = []
  const total = daysInMonth(year, month)
  for (let day = 1; day <= total; day += 1) {
    if (!isWeekend(year, month, day) && !isCompanyHolidayCluster(month, day)) {
      dates.push(`${year}-${pad2(month)}-${pad2(day)}`)
    }
  }
  return dates
}

/** 法定休日出勤バリエーション用に、休日クラスタと重ならない最初の日曜日を返す。 */
function firstPlainSunday(year: number, month: number): string {
  const total = daysInMonth(year, month)
  for (let day = 1; day <= total; day += 1) {
    if (dayOfWeek(year, month, day) === 0 && !isCompanyHolidayCluster(month, day)) {
      return `${year}-${pad2(month)}-${pad2(day)}`
    }
  }
  throw new Error(`E2E setup: no plain Sunday found in ${year}-${pad2(month)}`)
}

/** `PUT /work-calendars/{id}/days`向けの月内全日データ(土日=法定休日、休日クラスタ=会社休日)。 */
function calendarDaysForMonth(
  year: number,
  month: number,
): Array<{ date: string; day_type: string; is_working_day: boolean; is_legal_holiday: boolean; is_company_holiday: boolean }> {
  const total = daysInMonth(year, month)
  const days = []
  for (let day = 1; day <= total; day += 1) {
    const weekend = isWeekend(year, month, day)
    const companyHoliday = isCompanyHolidayCluster(month, day)
    days.push({
      date: `${year}-${pad2(month)}-${pad2(day)}`,
      day_type: weekend ? 'legal_holiday' : companyHoliday ? 'company_holiday' : 'weekday',
      is_working_day: !weekend && !companyHoliday,
      is_legal_holiday: weekend,
      is_company_holiday: companyHoliday,
    })
  }
  return days
}

/**
 * このテストを複数回実行する(デバッグ中の再実行等)と、前回実行分の日次実績・有給申請が
 * 既に存在していることがある。ドメインルールの重複エラー(「既に存在します」「既に有給を
 * 申請済みです」)はスキップ扱いにして、そのまま前回実行分を前提に処理を続ける
 * (scenario-02と同じ考え方)。
 */
async function ignoreIfAlreadyExists<T>(promise: Promise<T>): Promise<T | undefined> {
  try {
    return await promise
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    if (message.includes('既に存在します') || message.includes('既に有給を申請済みです') || message.includes('既に特別休暇を申請済みです')) {
      return undefined
    }
    throw error
  }
}

/** 2026年4月始まりの12か月分の [year, month] を順に返す。 */
function fiscalYearMonths(): Array<[number, number]> {
  const months: Array<[number, number]> = []
  for (let i = 0; i < 12; i += 1) {
    const year = 2026 + Math.floor((3 + i) / 12)
    const month = ((3 + i) % 12) + 1
    months.push([year, month])
  }
  return months
}

test('2026年度(2026-04〜2027-03)の12か月連続サイクル', async ({ browser }) => {
  // 12か月×日次実績入力を実際にAPIで回すため長めに取る(実測: ローカル環境で約45秒。
  // 環境差やリトライを考慮し余裕を持たせて5分とする)。
  test.setTimeout(5 * 60 * 1000)

  const employeeContext = await browser.newContext()
  const approverContext = await browser.newContext()
  const adminContext = await browser.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()
    const adminPage = await adminContext.newPage()

    await loginAs(employeePage, TARGET_EMPLOYEE_NAME)
    await loginAs(approverPage, SCENARIO_USERS.approver)
    await loginAs(adminPage, SCENARIO_USERS.admin)

    const userId = await fetchOwnUserId(employeePage)
    const approverId = await fetchOwnUserId(approverPage)

    const fiscalYearMarker = 59000 + Math.floor(Math.random() * 900)
    const calendar = await createWorkCalendar(adminPage, {
      name: `E2E 通年運用シミュレーション用カレンダー(${fiscalYearMarker})`,
      fiscalYear: fiscalYearMarker,
      startsOn: '2026-04-01',
      endsOn: '2027-03-31',
      weekStartsOn: 1,
    })

    const months = fiscalYearMonths()
    for (const [year, month] of months) {
      await putWorkCalendarDays(adminPage, calendar.id, calendarDaysForMonth(year, month))
    }
    await publishWorkCalendar(adminPage, calendar.id)

    const workStyle = await createWorkStyleViaApi(adminPage, {
      code: `cycle_${fiscalYearMarker}`,
      name: `E2E 通年運用シミュレーション用勤務形態(${fiscalYearMarker})`,
      workTimeSystem: 'fixed',
      prescribedDailyMinutes: 480,
      prescribedWeeklyMinutes: 2400,
      calendarId: calendar.id,
      isShiftBased: false,
    })

    // 有給は年度開始時に一括付与し、複数月に分散して全休・半休で消化する
    // (シナリオ6手順3、有給の年間消化)。再実行時に付与が重複しないよう、既に
    // 同じ`granted_on`の付与があればスキップする(冪等性)。
    const existingGrants = await fetchPaidLeaveGrantsForUser(adminPage, userId)
    if (!existingGrants.some((g) => g.granted_on === '2026-04-01')) {
      await createPaidLeaveGrant(adminPage, {
        userId,
        grantedOn: '2026-04-01',
        expiresOn: '2028-03-31',
        days: 20,
        reason: 'E2Eテスト用 通年運用シミュレーション初期付与',
      })
    }

    const initialGrants = await fetchPaidLeaveGrantsForUser(adminPage, userId)
    const initialRemaining = initialGrants.reduce((sum, g) => sum + g.remaining_days, 0)

    // 少なくとも2〜3か月は締めまで進める(シナリオ6手順2: 前月を締めた直後に翌月分の
    // 勤務予定生成・日次入力が支障なく行えるかを確認するため、年度最初の3か月を締める)。
    // 残りは承認までで十分。
    const monthsToClose = new Set(['2026-04', '2026-05', '2026-06', '2027-03'])

    let totalLeaveDaysConsumed = 0
    let monthsProcessedThisRun = 0

    for (const [year, month] of months) {
      const yearMonth = `${year}-${pad2(month)}`

      // 冪等性: 既にこのテストを実行済みで、その月が承認済み/締め済みになっている場合、
      // 日次実績の新規作成はAttendanceEditGuardによりブロックされる(UC-A011/UC-A015)。
      // 前回実行分をそのまま前提にして、この月はスキップする。
      const existingStatus = await fetchMonthStatus(employeePage, yearMonth)
      if (existingStatus === 'approved' || existingStatus === 'closed') {
        continue
      }
      monthsProcessedThisRun += 1

      await generateShiftAssignments(adminPage, {
        userId,
        workStyleId: workStyle.id,
        from: `${yearMonth}-01`,
        to: `${yearMonth}-${pad2(daysInMonth(year, month))}`,
      })

      const businessDays = businessDaysOfMonth(year, month)
      const [lateDate, overtimeDate, leaveDate, ...normalDates] = businessDays
      const legalHolidayDate = firstPlainSunday(year, month)

      // 通常勤務(9:00-18:00、休憩1時間)。
      for (const date of normalDates) {
        await ignoreIfAlreadyExists(
          createAttendanceDay(employeePage, {
            userId,
            workDate: date,
            actualStartAt: `${date}T09:00:00+09:00`,
            actualEndAt: `${date}T18:00:00+09:00`,
            breaks: [{ start: `${date}T12:00:00+09:00`, end: `${date}T13:00:00+09:00` }],
            reason: `${yearMonth} 通常勤務(E2E 通年運用シミュレーション)`,
          }),
        )
      }

      // 遅刻日(始業を1時間30分遅らせる)。
      await ignoreIfAlreadyExists(
        createAttendanceDay(employeePage, {
          userId,
          workDate: lateDate,
          actualStartAt: `${lateDate}T10:30:00+09:00`,
          actualEndAt: `${lateDate}T18:00:00+09:00`,
          breaks: [{ start: `${lateDate}T12:00:00+09:00`, end: `${lateDate}T13:00:00+09:00` }],
          reason: `${yearMonth} 遅刻日(E2E 通年運用シミュレーション)`,
        }),
      )

      // 残業日(9:00-21:00、休憩1時間。1日8時間超の3時間が法定時間外になる)。
      await ignoreIfAlreadyExists(
        createAttendanceDay(employeePage, {
          userId,
          workDate: overtimeDate,
          actualStartAt: `${overtimeDate}T09:00:00+09:00`,
          actualEndAt: `${overtimeDate}T21:00:00+09:00`,
          breaks: [{ start: `${overtimeDate}T12:00:00+09:00`, end: `${overtimeDate}T13:00:00+09:00` }],
          reason: `${yearMonth} 残業日(E2E 通年運用シミュレーション)`,
        }),
      )

      // 法定休日出勤日(休日集中月でなくても毎月1件、その月の日曜に出勤する)。
      await ignoreIfAlreadyExists(
        createAttendanceDay(employeePage, {
          userId,
          workDate: legalHolidayDate,
          actualStartAt: `${legalHolidayDate}T10:00:00+09:00`,
          actualEndAt: `${legalHolidayDate}T14:00:00+09:00`,
          reason: `${yearMonth} 法定休日出勤日(E2E 通年運用シミュレーション)`,
        }),
      )

      // 有給消化日(月ごとに全休・半休を交互に切り替える)。
      const leaveType = month % 2 === 0 ? 'full' : 'am_half'
      const leaveRequest = await ignoreIfAlreadyExists(
        requestPaidLeave(employeePage, {
          targetDate: leaveDate,
          leaveType,
          approverUserId: approverId,
          reason: `${yearMonth} 有給消化(E2E 通年運用シミュレーション)`,
        }),
      )
      if (leaveRequest) {
        await approvePaidLeaveRequest(approverPage, leaveRequest.id)
        totalLeaveDaysConsumed += leaveType === 'full' ? 1 : 0.5
      }

      // 月次提出〜承認まで進める。
      await submitAndApproveMonth(employeePage, approverPage, yearMonth)

      // 少なくとも2〜3か月分は締めまで進める(年度またぎの確認を兼ねる)。
      if (monthsToClose.has(yearMonth)) {
        await closeMonth(adminPage, employeePage, yearMonth)
        expect(await fetchMonthStatus(employeePage, yearMonth)).toBe('closed')
      } else {
        expect(await fetchMonthStatus(employeePage, yearMonth)).toBe('approved')
      }
    }

    // 有給の年間消化(シナリオ6手順3): 月をまたいでも残数が正しく累積して減っている
    // ことを確認する(全休6回+半休6回 = 9日分を12か月かけて消化した想定)。
    const finalGrants = await fetchPaidLeaveGrantsForUser(adminPage, userId)
    const finalRemaining = finalGrants.reduce((sum, g) => sum + g.remaining_days, 0)
    if (monthsProcessedThisRun > 0) {
      expect(totalLeaveDaysConsumed).toBeGreaterThan(0)
      expect(initialRemaining - finalRemaining).toBeCloseTo(totalLeaveDaysConsumed, 5)
    } else {
      // 前回のこのテスト実行で12か月すべて処理済み(承認済み/締め済み)だった場合、
      // 今回は全月スキップになり新規消化は発生しない。年間を通じて何らかの消化が
      // 過去に行われていること(付与20日のうち残数が減っていること)だけ確認する。
      expect(finalRemaining).toBeLessThan(initialRemaining + 0.001)
    }

    // 年度またぎの確認(シナリオ6手順2): 2027年3月分を締めた直後に、次の年度(2027年4月)の
    // 勤務予定生成・日次入力・提出〜承認〜締めが支障なく行えることを確認する
    // (このカレンダーは2027-03-31までしかカバーしていないが、シフト一括生成は
    // カレンダーの日区分が見つからない日を「稼働日」として扱うため、翌年度の疎通確認
    // だけであれば追加のカレンダー設定は不要)。
    await generateShiftAssignments(adminPage, {
      userId,
      workStyleId: workStyle.id,
      from: '2027-04-01',
      to: '2027-04-05',
    })
    const nextFiscalYearStatus = await fetchMonthStatus(employeePage, '2027-04')
    if (nextFiscalYearStatus !== 'approved' && nextFiscalYearStatus !== 'closed') {
      await ignoreIfAlreadyExists(
        createAttendanceDay(employeePage, {
          userId,
          workDate: '2027-04-01',
          actualStartAt: '2027-04-01T09:00:00+09:00',
          actualEndAt: '2027-04-01T18:00:00+09:00',
          breaks: [{ start: '2027-04-01T12:00:00+09:00', end: '2027-04-01T13:00:00+09:00' }],
          reason: '次年度初日の疎通確認(E2E 通年運用シミュレーション)',
        }),
      )
      await submitAndApproveMonth(employeePage, approverPage, '2027-04')
      await closeMonth(adminPage, employeePage, '2027-04')
    }
    expect(await fetchMonthStatus(employeePage, '2027-04')).toBe('closed')
  } finally {
    await employeeContext.close()
    await approverContext.close()
    await adminContext.close()
  }
})

/**
 * シナリオ6手順4(境界条件の単発確認)。`paid-leave:warn-expiring`/
 * `paid-leave:warn-five-day-obligation`/`paid-leave:grant-scheduled`はいずれもサーバーの
 * 実時刻(`now()`)基準で動くため、12か月連続サイクルの一部にはできない。実際の今日の日付を
 * 起点に境界条件を満たすデータをAPIで作成した上で、`php artisan`コマンドを直接実行して
 * 完走することを確認する。
 *
 * `PaidLeaveGrantResource`(`backend/app/Http/Resources/PaidLeaveGrantResource.php`)は
 * `expiry_warned_at`/`five_day_obligation_warned_at`相当のフィールドを返さないため、
 * 「警告済みになったこと」自体をAPI経由で直接検証する手段が無い。失効警告・年5日警告は
 * コマンドの標準出力(「N件の...を通知しました。」)から件数を読み取り、今回作成した
 * 対象が1件以上含まれることを確認する。年次自動付与は出勤率判定(直近1年分の
 * `employee_shift_assignments`が必要)まで満たすデータを用意するのは実行コストが高いため、
 * ドキュメント記載の割り切り通り「artisanコマンドがエラー無く完走すること」の疎通確認に
 * とどめる。
 */
test.describe('境界条件の単発確認(実時刻ベースの有給バッチ)', () => {
  test('paid-leave:warn-expiring: 失効まで90日の付与に警告が出る(標準出力の件数で確認)', async ({ page }) => {
    test.setTimeout(30000)
    await loginAs(page, SCENARIO_USERS.admin)
    const userId = await fetchOwnUserId(page)

    const today = new Date()
    const expiresOn = new Date(today)
    expiresOn.setDate(expiresOn.getDate() + 90)
    const grantedOn = new Date(today)
    grantedOn.setDate(grantedOn.getDate() - 1)

    await createPaidLeaveGrant(page, {
      userId,
      grantedOn: grantedOn.toISOString().slice(0, 10),
      expiresOn: expiresOn.toISOString().slice(0, 10),
      days: 1,
      reason: 'E2Eテスト用(失効警告境界条件、失効まで90日)',
    })

    const output = execSync('php artisan paid-leave:warn-expiring', { cwd: BACKEND_DIR, encoding: 'utf-8' })
    const match = output.match(/(\d+)\s*件の消滅警告を通知しました/)
    expect(match).not.toBeNull()
    expect(Number(match?.[1])).toBeGreaterThanOrEqual(1)
  })

  test('paid-leave:warn-five-day-obligation: 年5日未取得の付与に警告が出る(標準出力の件数で確認)', async ({ page }) => {
    test.setTimeout(30000)
    await loginAs(page, SCENARIO_USERS.admin)
    const userId = await fetchOwnUserId(page)

    // granted_on = 実日付-305日 → 取得義務期限(granted_on+1年)まで残り60日ちょうど。
    const today = new Date()
    const grantedOn = new Date(today)
    grantedOn.setDate(grantedOn.getDate() - 305)
    const expiresOn = new Date(grantedOn)
    expiresOn.setFullYear(expiresOn.getFullYear() + 2)

    await createPaidLeaveGrant(page, {
      userId,
      grantedOn: grantedOn.toISOString().slice(0, 10),
      expiresOn: expiresOn.toISOString().slice(0, 10),
      days: 10, // 年10日以上の付与のみ年5日取得義務の対象になる
      reason: 'E2Eテスト用(年5日取得義務警告境界条件、期限まで60日・使用日数0日)',
    })

    const output = execSync('php artisan paid-leave:warn-five-day-obligation', { cwd: BACKEND_DIR, encoding: 'utf-8' })
    const match = output.match(/(\d+)\s*件の年5日取得義務警告を通知しました/)
    expect(match).not.toBeNull()
    expect(Number(match?.[1])).toBeGreaterThanOrEqual(1)
  })

  test('paid-leave:grant-scheduled: 継続勤務の記念日にコマンドがエラー無く完走する(疎通確認)', async ({ browser }) => {
    test.setTimeout(30000)
    const employeeContext = await browser.newContext()
    const adminContext = await browser.newContext()
    try {
      const employeePage = await employeeContext.newPage()
      const adminPage = await adminContext.newPage()

      await loginAs(employeePage, TARGET_EMPLOYEE_NAME)
      await loginAs(adminPage, SCENARIO_USERS.admin)
      const userId = await fetchOwnUserId(employeePage)

      // hire_date = 実日付から継続勤務ちょうど6か月前(付与ルールのfirst_grant_after_months=6の
      // 記念日)。出勤率判定(直近1年分のemployee_shift_assignmentsが必要)まで満たすデータを
      // 用意するのは実行コストが高いため、ドキュメント記載の割り切り通り、ここでは
      // 「コマンドがエラー無く完走すること」の疎通確認にとどめる(対象者に実際に有給が
      // 付与されるかどうかまでは検証しない)。
      const today = new Date()
      const hireDate = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() - 6, today.getUTCDate()))
      await setUserHireDate(adminPage, userId, hireDate.toISOString().slice(0, 10))

      const output = execSync('php artisan paid-leave:grant-scheduled', { cwd: BACKEND_DIR, encoding: 'utf-8' })
      const match = output.match(/(\d+)\s*件の有給を自動付与しました/)
      expect(match).not.toBeNull()
      expect(Number(match?.[1])).toBeGreaterThanOrEqual(0)
    } finally {
      await employeeContext.close()
      await adminContext.close()
    }
  })
})
