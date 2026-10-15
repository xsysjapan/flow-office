# 月次項目・給与連携・出勤率の入力の調査(2026-10-10、investigator調査・委譲元で一部検証済み)

パス略称: Calc=Attendance/Services/AttendanceCalculator.php、MOC=Attendance/Services/MonthlyOvertimeCalculator.php、
Submit=Attendance/Handlers/SubmitAttendanceMonthHandler.php、Recalc=Attendance/Handlers/RecalculateAttendanceMonthSnapshotHandler.php

## 月次スナップショット(Submit:94-103、Recalc:70-78、MOC:84-176)
- `day_count`: 勤怠日の行数(休暇だけの日も行があれば+1)。
- 日次計算の列の合計: work/payroll/prescribed_work_minutes、法定内外・深夜・休日労働、absence_minutes、paid/special_leave_days・minutes ほか(MOC:118-147)。
- 日次計算からの件数: absence_days(所定>0かつ欠勤>=所定)、worked_days・work_days_*(work>0)。
- 区分別(weekday_*/prescribed_holiday_*)は day_classification、週40時間超は週次計算。
- スナップショット外: flex_settlement_summary、special_leave_breakdown(特別休暇の消化記録を直接読む)。

## 休暇だけの日の日次計算の値(通常勤務日・所定P)
| 項目 | 有給 全/半/時間 | 特休 全/半/時間 | 代休 全/半/時間 |
|---|---|---|---|
| prescribed_work_minutes | P / P÷2 / P | P / P÷2 / P | P / P÷2 / P |
| paid_leave_days | 1.0 / 0.5 / 0 | 0 | 0 |
| paid_leave_minutes | 0 / 0 / 時間休分 | 0 | 0 |
| special_leave_days | 0 | 1.0 / 0.5 / 0 | 0 |
| special_leave_minutes | 0 | 0 / 0 / 時間休分 | 0 |
| work・absence・法定内外・深夜 | 0 | 0 | 0 |
- Pは勤務形態の所定(Calc:152)。法定休日・所定休日はprescribed=0。勤務予定が無くても`EffectiveScheduleResolver`の仮想予定で同じ値。裁量労働制は所定稼働日にpayroll=みなし時間。

## 給与連携・帳票
- MoneyForward CSV・freee API・その他CSV: スナップショットのみ使用(freeeの`total_normal_work_mins`=prescribed_work_minutes、`num_paid_holidays`=paid_leave_days)。
- Excel: 日別行は勤怠日から作る。行が無いと空欄になり、「有給休暇」の注記(日次計算の休暇日数から作る)も消える。必要出勤日数は公開済みカレンダー件数。

## 画面API(AttendanceController)
- today: 行が無ければ未保存の行を返す。休暇情報なし。week: 勤怠日の行のみ(休暇だけで行が無い日は欠落)。month: days=行のみ、scheduleはカレンダー+仮想予定。

## 出勤率
- `AttendanceRateAssessor`(:28-92): 分母=`employee_calendar_entries.is_working_day=true`(期間内)。分子=勤怠日のうち`status=clocked_out`または`work_type LIKE 'paid_leave_%'`で、分母日と重なるもの。
- `GrantScheduledSpecialLeaveHandler`(:157-189): 分子にspecial_leave_%も含む(Assessorと差異)。
- 全休は3種とも勤怠日を`clocked_out`にするため実際には出勤扱い。半休・時間休はwork_typeのみで判定され、代休の半休・時間休は分子に入らない。
- イベント: `attendance_day.calculated`はcalculation配列のみ(利用者・日付・status無し)。`employee_calendar_entry.assigned`は
  userId・workDate・isWorkingDay・isLegalHoliday・isCompanyHolidayを持つ(委譲元で検証済み)。
