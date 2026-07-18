import { expect, test, type Page } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'
import { recordAttendancePunch, submitAndApproveMonth } from './support/api'
import { goToAttendanceWeekContaining } from './support/ui'

/**
 * docs/testing/scenario-tests.md §5-11/§5-12(その他、用意しておくべきシナリオ)に対応する。
 *
 * §5-11は日次勤怠画面へ直接遷移するだけなので、実在しない遠い未来の年(`randomFarFutureDate`)
 * を使い、月次承認による恒久的なブロックを気にしなくてよいようにする。§5-12は週次画面の
 * 週送りボタンで移動する都合上、実時刻から数十か月先までの範囲でランダムな月を選ぶ
 * (`nthOfMonthsAhead`)。固定の日付・月にすると、このテストを複数回実行した際に前回分の
 * 打刻ログの蓄積や月次承認済み状態が残り、「承認前は削除できる」という前提が崩れるため
 * (当月は他のシナリオ(§5-3/§5-8, `submitApproveAndCloseCurrentMonth`)が締めまで進めて
 * しまうこともある)。
 *
 * 【ドキュメントとの差異】打刻ログの訂正・削除、日次勤怠の編集・削除は、当初は週次画面
 * (`WeekAttendancePage`)の各行にインラインで実装されていたが、その後専用の日次勤怠画面
 * (`/attendance/days/{date}`、`AttendanceDayPage`)に集約された(2026-07時点でのUI変更)。
 * 週次画面の行(`AttendanceDayRow`)は状態バッジ・労働時間の概要表示のみを担うオブジェクト
 * 指向UIのリンクとなり、実際の訂正・削除・編集操作は日次画面で行う。加えて、打刻ログの
 * 訂正・削除ボタンは常時表示ではなく、「ログを編集」ボタンで編集モードに切り替えてから
 * 表示される(誤操作防止のため)。本ファイルはこの構成に合わせて実装している。
 */
/**
 * `dayOfMonth`を基準に±`jitterDays`の範囲でランダムにずらした、`monthsAhead`か月先の日付を
 * 返す。日付を固定にすると、このテストを複数回実行した際に前回分の打刻ログ(訂正・削除
 * しても打刻ログ自体は残り続ける)が同じ日に積み重なり、「有効な出勤打刻が1件」という
 * 前提が崩れるため、実行のたびに毎回別の日を使う(scenario-00/07と同じ考え方)。
 */
function nthOfMonthsAhead(monthsAhead: number, dayOfMonth: number, jitterDays = 9): string {
  const now = new Date()
  const jitter = Math.floor(Math.random() * (jitterDays * 2 + 1)) - jitterDays
  const date = new Date(now.getFullYear(), now.getMonth() + monthsAhead, dayOfMonth + jitter)
  return date.toISOString().slice(0, 10)
}

/**
 * 実在の暦年(2026年)や他シナリオの年レンジ(scenario-00/07: 3000〜8999年度、
 * scenario-09: 9000年台、scenario-08: 59000番台)と衝突しない、1000〜1999年のいずれかの
 * 日付を返す(`punched_at`のバリデーション(`LocalDateTime::OFFSET_REQUIRED_RULE`)が
 * 4桁の年を前提にした正規表現のため、5桁以上の年は使えない)。§5-11は
 * `goToAttendanceWeekContaining`(週送りボタンをクリックで移動する、実時刻からの週数ぶん
 * クリックする)を使わず`/attendance/days/{date}`へ直接遷移するだけなので、
 * `nthOfMonthsAhead`(週送りクリック数を現実的な範囲に抑えるため実時刻からの近い将来
 * しか使えない)と異なり、日付が実時刻からどれだけ離れていても問題ない。そのため衝突確率が
 * 実質ゼロになる年を使い、月次承認による恒久的なブロック(AttendanceEditGuard)を
 * 気にしなくてよいようにする。
 */
function randomFarFutureDate(): string {
  const year = 1000 + Math.floor(Math.random() * 1000)
  const month = 1 + Math.floor(Math.random() * 12)
  const day = 5 + Math.floor(Math.random() * 20)
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * 週次画面の日次勤怠行(`<li class="py-3">`)だけを指す。日付の直後に曜日の丸括弧が続く
 * 見出し("2026-11-20(金)")で絞り込む。
 */
function dayRow(page: Page, date: string) {
  return page.getByRole('listitem').filter({ hasText: `${date}(` })
}

test('§5-11: 打刻ログの訂正・削除', async ({ page }) => {
  test.setTimeout(30000)
  const targetDate = randomFarFutureDate()

  await loginAs(page, SCENARIO_USERS.punchEmployee)

  // 出勤時刻を09:30と打刻したが、本来は09:00だった(入力ミス)。
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_in', punchedAt: `${targetDate}T09:30:00+09:00` })
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_out', punchedAt: `${targetDate}T18:00:00+09:00` })

  // 日次勤怠画面(週次画面の行から遷移する代わりに直接開く)で実績を確認する。
  await page.goto(`/attendance/days/${targetDate}`)
  await expect(page.getByRole('main')).toContainText('09:30')

  // 打刻ログを編集モードにし、誤った出勤打刻を訂正する(UC-A013)。
  await page.getByRole('button', { name: 'ログを編集' }).click()
  const clockInPunchRow = page.getByRole('listitem').filter({ hasText: '出勤' }).filter({ hasText: '有効' })
  await expect(clockInPunchRow).toHaveCount(1)
  await clockInPunchRow.getByRole('button', { name: '訂正' }).click()

  const datetimeInput = clockInPunchRow.getByLabel('訂正後の日時')
  await datetimeInput.fill(`${targetDate}T09:00`)
  await clockInPunchRow.getByLabel('訂正理由').fill('打刻時刻の入力ミス')
  await clockInPunchRow.getByRole('button', { name: '訂正を保存' }).click()

  // 日次勤怠は訂正後の時刻(09:00)に組み立て直される。元の打刻(09:30)は削除されず
  // 「訂正済み」として理由付きで参照できる(打刻ログは追記のみのため。編集モードのままなので
  // 訂正済みの打刻も引き続き一覧に表示される)。
  await expect(page.getByRole('main')).toContainText('09:00')
  const correctedOriginalRow = page.getByRole('listitem').filter({ hasText: '訂正済み' })
  await expect(correctedOriginalRow).toContainText('09:30')
  await expect(correctedOriginalRow).toContainText('打刻時刻の入力ミス')

  // 重複打刻(誤って2回目の出勤打刻をしてしまったケース)を記録し、削除する(UC-A014)。
  await recordAttendancePunch(page, { workDate: targetDate, punchType: 'clock_in', punchedAt: `${targetDate}T09:45:00+09:00` })

  // usePunches はページ再読み込みで最新化する。
  await page.reload()
  await page.getByRole('button', { name: 'ログを編集' }).click()

  const duplicatePunchRow = page.getByRole('listitem').filter({ hasText: '09:45' })
  await duplicatePunchRow.getByRole('button', { name: '削除' }).click()
  await duplicatePunchRow.getByLabel('削除理由').fill('二重打刻の削除')
  await duplicatePunchRow.getByRole('button', { name: '削除する' }).click()

  await expect(duplicatePunchRow.filter({ hasText: '削除済み' })).toContainText('二重打刻の削除')
  // 削除後も日次勤怠(09:00〜18:00)は正しく組み立てられたままになる。
  await expect(page.getByRole('main')).toContainText('09:00')
})

test('§5-12: 日次勤怠の削除(承認前は削除できるが、承認後は削除・変更できない)', async ({ browser }) => {
  // `goToAttendanceWeekContaining`の週送りクリック回数が対象月次第で増えるため、
  // 通常より長めのタイムアウトを取る。
  test.setTimeout(120000)
  // 削除後に同じ日へ打刻をやり直すと、削除前の打刻ログが残ったままになり
  // (削除APIは打刻ログではなく日次勤怠だけを消す)矛盾扱いになってしまう。そのため
  // 「削除できることの確認」と「削除できないことの確認」は同じ月内の別々の日で行う。
  // 対象月自体もランダムにする(このテストは`protectedDate`側の月次を承認済みまで進めるため、
  // 固定の月だと再実行のたびに同じ月が既に承認済みになり、AttendanceEditGuardにより
  // `deletableDate`側の日次実績すら二度と作成できなくなってしまう)。この日付は
  // `goToAttendanceWeekContaining`(実時刻からの週数ぶん週送りボタンをクリックする)で
  // 画面移動するため、§5-11の`randomFarFutureDate`のような遠い未来の年は使えない
  // (クリック回数が非現実的になる)。実時刻から2〜26か月先の範囲でランダムに選ぶ。
  const monthsAhead = 2 + Math.floor(Math.random() * 24)
  const deletableDate = nthOfMonthsAhead(monthsAhead, 10, 4)
  const protectedDate = nthOfMonthsAhead(monthsAhead, 20, 4)
  const targetYearMonth = deletableDate.slice(0, 7)

  const employeeContext = await browser.newContext()
  const approverContext = await browser.newContext()
  try {
    const employeePage = await employeeContext.newPage()
    const approverPage = await approverContext.newPage()

    await loginAs(employeePage, SCENARIO_USERS.punchEmployee)
    await loginAs(approverPage, SCENARIO_USERS.approver)

    // 承認前(未提出)は削除できる(UC-A015)。
    await recordAttendancePunch(employeePage, { workDate: deletableDate, punchType: 'clock_in', punchedAt: `${deletableDate}T09:00:00+09:00` })
    await recordAttendancePunch(employeePage, { workDate: deletableDate, punchType: 'clock_out', punchedAt: `${deletableDate}T18:00:00+09:00` })

    await goToAttendanceWeekContaining(employeePage, deletableDate)
    const deletableRow = dayRow(employeePage, deletableDate)
    await expect(deletableRow).toContainText('退勤済み')

    // 週次画面の行から日次勤怠画面へ遷移し、そこで削除する。
    await deletableRow.click()
    await expect(employeePage).toHaveURL(new RegExp(`/attendance/days/${deletableDate}$`))
    await employeePage.getByRole('button', { name: '削除' }).click()
    await employeePage.getByLabel('削除理由').fill('二重入力の削除(E2E)')
    // 打刻ログの扱いは既定の「そのまま残す」のままで削除する。
    await employeePage.getByRole('button', { name: '削除する' }).click()

    // 削除後、週次画面へ戻る(AttendanceDayPageのonDeletedがnavigate(-1)するため)。
    // 週次画面は`?start=`が無いと当週から表示し直すため(WeekAttendancePageの初期状態)、
    // 対象日を含む週まで改めて移動する。
    await expect(employeePage).toHaveURL(/\/attendance\/week/)
    await goToAttendanceWeekContaining(employeePage, deletableDate)
    await expect(dayRow(employeePage, deletableDate)).toContainText('未入力')

    // 別日の実績を入力し、月次を提出〜承認まで進める。
    await recordAttendancePunch(employeePage, { workDate: protectedDate, punchType: 'clock_in', punchedAt: `${protectedDate}T09:00:00+09:00` })
    await recordAttendancePunch(employeePage, { workDate: protectedDate, punchType: 'clock_out', punchedAt: `${protectedDate}T18:00:00+09:00` })
    await submitAndApproveMonth(employeePage, approverPage, targetYearMonth)

    // 締め(locked_at)はまだ行われていないが、月次が承認済みになった時点で削除・編集は
    // どちらもできなくなる(既存実装の抜け穴の修正確認。UC-A005/UC-A015)。
    await goToAttendanceWeekContaining(employeePage, protectedDate)
    const protectedRow = dayRow(employeePage, protectedDate)
    await expect(protectedRow).toContainText('退勤済み')

    await protectedRow.click()
    await expect(employeePage).toHaveURL(new RegExp(`/attendance/days/${protectedDate}$`))

    await employeePage.getByRole('button', { name: '削除' }).click()
    await employeePage.getByLabel('削除理由').fill('承認後の削除テスト(E2E)')
    await employeePage.getByRole('button', { name: '削除する' }).click()
    await expect(
      employeePage.getByRole('alert').filter({ hasText: '承認済みの月次勤怠に含まれる日次勤怠は修正申請から変更してください。' }),
    ).toBeVisible()

    await employeePage.keyboard.press('Escape')
    // 「ログを編集」(打刻ログカード)と区別するため完全一致で指定する。
    await employeePage.getByRole('button', { name: '編集', exact: true }).click()
    await employeePage.getByLabel('修正理由(必須)').fill('承認後編集の拒否確認(E2E)')
    await employeePage.getByRole('button', { name: '保存する' }).click()
    await expect(
      employeePage.getByRole('alert').filter({ hasText: '承認済みの月次勤怠に含まれる日次勤怠は修正申請から変更してください。' }),
    ).toBeVisible()
  } finally {
    await employeeContext.close()
    await approverContext.close()
  }
})
