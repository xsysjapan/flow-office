import { expect, test } from '@playwright/test'
import { loginAs, SCENARIO_USERS } from './support/auth'

/**
 * docs/testing/scenario-tests.md シナリオ2(月次入力ユーザーの1か月勤怠)。
 *
 * 【確認済みの既知の欠落機能】
 * 月次入力ユーザー(伊藤舞)は打刻APIを使わず、日次編集だけで1か月分の実績を入力する
 * 想定だったが、調査の結果これは**現状のAPI/画面では不可能**であることが分かった。
 *
 * - `PUT /attendance/days/{attendanceDay}` はLaravelのroute-model bindingで
 *   既存の`attendance_days`行のIDを要求するため、まだ1度も打刻・編集していない日の
 *   行を新規作成するエンドポイントが存在しない
 *   (backend/app/Domain/Attendance/Handlers/EditAttendanceDayHandler.php:38
 *   `AttendanceDay::query()->findOrFail(...)`)。
 * - `attendance_days`行は (1) 打刻(`POST /attendance/clock-in`)、または
 *   (2) 有給申請の承認(`ApprovePaidLeaveRequestHandler::reflectOnAttendanceDay`)
 *   の2経路でしか新規作成されない。
 * - そのためフロントエンドの週次勤怠画面(`WeekAttendancePage`)でも、行がまだ存在しない
 *   日は「編集」ボタン自体が表示されない({@link WeekDayRow} は `day &&
 *   !isEditing` の場合のみ編集ボタンを出す)。
 *
 * つまり打刻を一切しない社員は、有給を取った日以外は勤怠を入力する手段がない。
 * このテストはその現状の挙動を固定して記録するものであり、`docs/10-usecases-workflow.md`
 * 等でこの運用を認める(月次のみ入力ユーザーを実際にサポートする)方針であれば、
 * 「日次実績を新規作成するAPI」を追加したうえで、本テストを本来のシナリオ
 * (日次編集→月次提出→承認→締め)に書き換える必要がある。
 */
test('月次入力ユーザーは、打刻も有給申請もしていない日には勤怠編集ボタンが表示されない(既知の制限)', async ({
  page,
}) => {
  await loginAs(page, SCENARIO_USERS.monthlyEmployee)
  await page.goto('/attendance/week')

  // 未入力の日が少なくとも1つあり、その行に「編集」ボタンが存在しないことを確認する
  // (=このAPI/画面だけでは日次実績を新規作成できない)。
  const daysWithoutRecord = page.getByRole('listitem').filter({ hasText: '未入力' })
  await expect(daysWithoutRecord.first()).toBeVisible()
  for (const row of await daysWithoutRecord.all()) {
    await expect(row.getByRole('button', { name: '編集' })).toHaveCount(0)
  }
})

test.skip(
  '月次入力ユーザーが日次編集のみで1か月の勤怠を入力できる (TODO: 上記の既知の制限が解消されてから実装する)',
  async () => {
    // 1. /attendance/week で対象日をクリック
    // 2. 出勤・退勤・休憩時刻を入力して保存 (source が manual になることを確認)
    // 3. 月末に /attendance/months から月次提出
    // 4. 承認者(渡辺直樹)で承認、人事担当者(加藤由美)で締め
  },
)
