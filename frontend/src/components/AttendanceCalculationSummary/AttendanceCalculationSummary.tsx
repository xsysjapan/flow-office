import type { ReactNode } from 'react'
import { Duration } from '../Duration/Duration'

/** 特別休暇の種類ごとの内訳(月次はバックエンド算出、週次はフロントエンドでの
 *  クライアントサイド集計)。両者で同じ形にして本コンポーネントに渡す。 */
export interface AttendanceSpecialLeaveBreakdownItem {
  special_leave_type_id: string
  special_leave_type_name: string
  days: number
  minutes: number
}

export interface AttendanceCalculationSummaryData {
  /** 実労働時間(実際に働いた時間の合計)。給与計算上の労働時間(payroll_work_minutes)は
   *  裁量労働制・みなし労働時間制以外では通常この値と一致するため、別行では表示しない
   *  (実際に異なる場合のみ本コンポーネント側でpayrollWorkMinutesを併記する)。 */
  work_minutes?: number
  prescribed_work_minutes: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  late_night_prescribed_work_minutes: number
  late_night_statutory_within_overtime_minutes: number
  late_night_statutory_excess_overtime_minutes: number
  legal_holiday_work_minutes: number
  late_night_legal_holiday_work_minutes: number
  prescribed_holiday_work_minutes: number
  late_night_prescribed_holiday_work_minutes: number
  absence_minutes?: number
  paid_leave_days?: number
  paid_leave_minutes?: number
  special_leave_days?: number
  special_leave_minutes?: number
}

export interface AttendanceCalculationSummaryProps {
  title: string
  totals: AttendanceCalculationSummaryData
  statutoryExcessOver60hMinutes?: number
  /** 週40時間(労基法32条)超残業の月内全週合計。月次確認画面のみ指定する。 */
  weeklyStatutoryExcessOvertimeMinutes?: number
  absenceDays?: number
  showAllLeaveTotals?: boolean
  /** 特別休暇の種類ごとの内訳。未指定の場合は従来通り合計(special_leave_days/minutes)のみ表示する。 */
  specialLeaveBreakdown?: AttendanceSpecialLeaveBreakdownItem[]
  /** 給与計算上の労働時間(裁量労働制等でwork_minutesと異なる場合のみ)。work_minutesと
   *  一致する場合は表示しない(表示の重複を避けるため呼び出し側で判定せず、本コンポーネントに
   *  常に渡してよい)。 */
  payrollWorkMinutes?: number
}

function SummaryItem({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="whitespace-nowrap font-medium text-muted-foreground">{label}</dt>
      <dd className="justify-self-end whitespace-nowrap text-foreground sm:justify-self-auto">{children}</dd>
    </>
  )
}

/** 日次・週次・月次で共通の勤怠集計。モバイルではラベルと値を1組ずつ表示する。 */
export function AttendanceCalculationSummary({
  title,
  totals,
  statutoryExcessOver60hMinutes,
  weeklyStatutoryExcessOvertimeMinutes,
  absenceDays,
  showAllLeaveTotals = false,
  specialLeaveBreakdown,
  payrollWorkMinutes,
}: AttendanceCalculationSummaryProps) {
  const hasLeaveTotals = showAllLeaveTotals
    || !!totals.absence_minutes
    || !!totals.paid_leave_days
    || !!totals.paid_leave_minutes
    || !!totals.special_leave_days
    || !!totals.special_leave_minutes
    || absenceDays !== undefined

  // 特別休暇は種類がわかりにくいという声があったため、渡されていれば1種類のみでも
  // 内訳行(種類名付き)を表示する(合計行は「特別休暇」としか出ないため、種類名は
  // この内訳行でしか分からない)。
  const specialLeaveTypeBreakdown = (specialLeaveBreakdown ?? []).filter((item) => item.days !== 0 || item.minutes !== 0)
  const showSpecialLeaveTypeBreakdown = specialLeaveTypeBreakdown.length > 0

  // 給与計算上の労働時間(payroll_work_minutes)は裁量労働制・みなし労働時間制以外では
  // work_minutesと一致するため、異なる場合のみ別行で表示する(常に両方出すと大半のケースで
  // 同じ数字が並んで見づらいため)。
  const showPayrollWorkMinutes = payrollWorkMinutes !== undefined && payrollWorkMinutes !== totals.work_minutes

  return (
    <section aria-labelledby={`${title}-summary`}>
      <h3 id={`${title}-summary`} className="mb-3 text-sm font-medium text-foreground">{title}</h3>
      <dl className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
        {totals.work_minutes !== undefined && (
          <SummaryItem label="実労働時間"><Duration minutes={totals.work_minutes} /></SummaryItem>
        )}
        {showPayrollWorkMinutes && (
          <SummaryItem label="給与計算上の労働時間"><Duration minutes={payrollWorkMinutes} /></SummaryItem>
        )}
        <SummaryItem label="所定労働時間"><Duration minutes={totals.prescribed_work_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜所定労働時間"><Duration minutes={totals.late_night_prescribed_work_minutes} /></SummaryItem>
        <SummaryItem label="法定内残業時間"><Duration minutes={totals.statutory_within_overtime_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定内残業時間"><Duration minutes={totals.late_night_statutory_within_overtime_minutes} /></SummaryItem>
        <SummaryItem label="法定外残業時間"><Duration minutes={totals.statutory_excess_overtime_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定外残業時間"><Duration minutes={totals.late_night_statutory_excess_overtime_minutes} /></SummaryItem>
        {statutoryExcessOver60hMinutes !== undefined && (
          <SummaryItem label="うち月60時間超"><Duration minutes={statutoryExcessOver60hMinutes} /></SummaryItem>
        )}
        {weeklyStatutoryExcessOvertimeMinutes !== undefined && (
          <SummaryItem label="うち週40時間超"><Duration minutes={weeklyStatutoryExcessOvertimeMinutes} /></SummaryItem>
        )}
        <SummaryItem label="法定休日労働時間"><Duration minutes={totals.legal_holiday_work_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定休日労働時間"><Duration minutes={totals.late_night_legal_holiday_work_minutes} /></SummaryItem>
        <SummaryItem label="所定休日労働時間"><Duration minutes={totals.prescribed_holiday_work_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜所定休日労働時間"><Duration minutes={totals.late_night_prescribed_holiday_work_minutes} /></SummaryItem>
      </dl>

      {hasLeaveTotals && (
        <dl className="mt-3 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-t border-border pt-3 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
          {absenceDays !== undefined && <SummaryItem label="欠勤日数">{absenceDays}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.absence_minutes) && <SummaryItem label="欠勤時間"><Duration minutes={totals.absence_minutes ?? 0} /></SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_days) && <SummaryItem label="有給日数">{totals.paid_leave_days ?? 0}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_minutes) && <SummaryItem label="有給時間(時間単位)"><Duration minutes={totals.paid_leave_minutes ?? 0} /></SummaryItem>}
          {(showAllLeaveTotals || !!totals.special_leave_days) && <SummaryItem label="特別休暇日数">{totals.special_leave_days ?? 0}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.special_leave_minutes) && <SummaryItem label="特別休暇時間"><Duration minutes={totals.special_leave_minutes ?? 0} /></SummaryItem>}
          {showSpecialLeaveTypeBreakdown && specialLeaveTypeBreakdown.map((item) => (
            <SummaryItem key={item.special_leave_type_id} label={`うち${item.special_leave_type_name}`}>
              {item.days > 0 && `${item.days}日`}
              {item.days > 0 && item.minutes > 0 && ' '}
              {item.minutes > 0 && <Duration minutes={item.minutes} />}
            </SummaryItem>
          ))}
        </dl>
      )}
    </section>
  )
}