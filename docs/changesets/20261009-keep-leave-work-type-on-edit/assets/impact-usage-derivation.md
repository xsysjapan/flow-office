# 方針B(休暇をusageから導出)の影響範囲調査(2026-10-10、investigator調査・委譲元で一部検証済み)

行番号は調査時点(ブランチ claude/claude-md-delegation-rules)。

## usageテーブル
| | テーブル | attendance_day_id | 取消の表現 | Projector |
|---|---|---|---|---|
| 有給 | paid_leave_usages | nullable(2026_09_07_000001で変更) | `cancelled=true`(行は残る) | PaidLeaveAccount/Projectors/PaidLeaveUsageAllocationProjector.php(designated:127, confirmed:153, cancelled:163) |
| 特別 | special_leave_usages | NOT NULL | 行を削除 | SpecialLeave/Projectors/SpecialLeaveUsageProjector.php(designated:26, used:44, reversed:83, request_cancelled:97) |
| 代休 | compensatory_leave_usages | NOT NULL | 行を削除 | CompensatoryLeave/Projectors/CompensatoryLeaveGrantProjector.php(used:112, designated:153, reversed:177, request_cancelled:196) |

- 共通列: used_on, used_days, used_minutes, usage_type(full/am_half/pm_half/hourly), is_confirmed, stored_event_id。
- 状態別: 申請中=行あり(is_confirmed=false)/承認済=確定(特別・代休はgrantごとに行分割)/差戻し=行は残る/
  取消=有給はcancelled=true、特別・代休は削除/承認済だが残数不足=特別・代休でis_confirmed=falseのまま残る。
- 取得単位は used_days ではなく usage_type で判定する(残数不足時は used_days が部分量)。
- 同日併存: 申請時の重複ガードが非対称(有給:有給・特別のみ RequestPaidLeaveHandler.php:91-109、
  特別:有給・特別のみ RequestSpecialLeaveHandler.php:180、代休:3種 RequestCompensatoryLeaveHandler.php:183)で、有給+代休等が併存しうる。

## work_type休暇値の書き込み(削除対象)
- 有給 申請 RequestPaidLeaveHandler.php:179/188/190-193(全休→CLOCKED_OUT)、計算158-161
- 有給 取消 CancelPaidLeaveRequestHandler.php:88/94(NOT_STARTED、打刻無しのみ)/96、計算98-102
- 特別 申請 RequestSpecialLeaveHandler.php:165/168/170、計算135-138 / 取消 CancelSpecialLeaveRequestHandler.php:91/97/99、計算101-105
- 代休 申請 RequestCompensatoryLeaveHandler.php:154/157/159、計算120-123 / 取消 CancelCompensatoryLeaveRequestHandler.php:95/101/103、計算105-109
- 全休のstatus=clocked_outは「打刻漏れ」警告回避目的。判定は frontend/src/utils/attendanceDayWarnings.ts:26(検証済み)。

## 休暇値の読み手(置き換え対象)
| 箇所 | 用途 | 置き換え後に必要なデータ |
|---|---|---|
| backend/app/Domain/Attendance/Services/AttendanceCalculator.php 125-161 | 有給・特別の日数、半休の所定半減(代休は未参照) | 当日usage(usage_type、有給はcancelled=false) |
| backend/app/Domain/PaidLeaveSchedule/Support/AttendanceRateAssessor.php 70-73 | 出勤率 | 有給usageの有無 |
| backend/app/Domain/SpecialLeave/Handlers/GrantScheduledSpecialLeaveHandler.php 176-181 | 出勤率 | 有給・特別usageの有無 |
| backend/app/Domain/Attendance/Services/AttendanceDayPunchSyncer.php 286, 303-316 | 計算用relation読込(代休未読込) | 3種usage |
| backend/app/Http/Resources/AttendanceDayResource.php 34, 59-68 | API出力(有給usage未出力) | 休暇情報 |
| backend/app/Http/Controllers/Api/AttendanceController.php 78, 199, 684 / 337(showDay) | eager load(特別のみ) | 3種usageのrelation(代休relationは未定義) |
| backend/app/Domain/AttendanceImport/Services/AttendanceDifferenceDetector.php 80-81 | 休暇重複警告(取消済有給を拾う既存不具合) | cancelled条件 |
| backend/app/Domain/Attendance/Services/MonthlyOvertimeCalculator.php 200 | 特別休暇内訳 | - |
| backend/app/Domain/Attendance/Handlers/DeleteAttendanceDayHandler.php 49-55 | 削除ガード(代休未ガード) | 3種usage |
| frontend/src/utils/statusLabels.ts 162-196 | 休暇ラベル | APIの休暇情報 |
| frontend/src/utils/attendanceDayWarnings.ts 26 | 打刻漏れ判定 | 全休usageの有無 |
| frontend/src/pages/attendance/AttendanceDayPage.tsx 566-580, 695, 716, 729, 898, 1254-1256 | 休暇指定UI・作業内容 | 当日の休暇情報 |

- 推奨: 勤怠APIに休暇情報(種別・単位・確定有無・申請ID)を含め、AttendanceDayに3種usageのrelationを追加して既存eager loadに載せる。
  週次・月次・今日の画面は申請一覧を取得していない(取得しているのは日次画面のみ、かつ全件・期間絞りなし)。

## 再計算
- 日次計算の一括再実行コマンドは無い。`attendance:recalculate-month-snapshots`の対象はSUBMITTED/APPROVED/CLOSEDの月のみ。

## テスト・docs(休暇値前提)
- backend: tests/Feature/PaidLeaveAccount/PaidLeaveRequestTest.php、tests/Feature/SpecialLeave/SpecialLeaveRequestTest.php、
  tests/Feature/CompensatoryLeave/CompensatoryLeaveTest.php、tests/Feature/Attendance/HalfDayLeavePrescribedMinutesTest.php、
  tests/Unit/PaidLeaveSchedule/AttendanceRateAssessorTest.php
- frontend: AttendanceDayPage.test.tsx、WeekAttendancePage.test.tsx、TodayAttendancePanel.test.tsx、AttendanceDayRow.test.tsx、
  utils/statusLabels.test.ts、utils/attendanceDayWarnings.test.ts、e2e/scenario-03-paid-leave.spec.ts
- docs: 07-usecases-attendance.md(115,136,439)、09-usecases-paid-leave.md(90,212-216,607-616)、16-database-schema.md(565-568,596,917)、
  testing/scenario-tests.md:149、29-event-sourcing-framework-migration.md:510

## 仕様検討で決める必要がある論点(調査で判明)
1. 差戻し中(usage行が残る)の日を休暇として表示・集計するか
2. 承認済だが未充当(is_confirmed=false)を休暇として扱うか
3. 同日の休暇併存(有給+代休等)を許すか(重複ガードの統一)
4. 既存の attendance_days.work_type 休暇値の扱い(読まなくなるだけでよいか)
5. 全休日の status=clocked_out 直接書き換えの代替(打刻漏れ判定に全休usageを加える等)
6. 代休の削除ガード・日次集計への算入
