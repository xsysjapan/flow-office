import type { ReactNode } from 'react'
import { Duration } from '../Duration/Duration'

export interface AttendanceCalculationSummaryData {
  prescribed_work_minutes: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  late_night_prescribed_work_minutes: number
  late_night_statutory_within_overtime_minutes: number
  late_night_statutory_excess_overtime_minutes: number
  legal_holiday_work_minutes: number
  late_night_legal_holiday_work_minutes: number
  absence_minutes?: number
  special_leave_minutes?: number
  paid_leave_days?: number
  paid_leave_minutes?: number
}

export interface AttendanceCalculationSummaryProps {
  title: string
  totals: AttendanceCalculationSummaryData
  statutoryExcessOver60hMinutes?: number
  absenceDays?: number
  specialLeaveDays?: number
  showAllLeaveTotals?: boolean
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
  absenceDays,
  specialLeaveDays,
  showAllLeaveTotals = false,
}: AttendanceCalculationSummaryProps) {
  const hasLeaveTotals = showAllLeaveTotals
    || !!totals.absence_minutes
    || !!totals.special_leave_minutes
    || !!totals.paid_leave_days
    || !!totals.paid_leave_minutes
    || absenceDays !== undefined
    || specialLeaveDays !== undefined

  return (
    <section aria-labelledby={`${title}-summary`}>
      <h3 id={`${title}-summary`} className="mb-3 text-sm font-medium text-foreground">{title}</h3>
      <dl className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
        <SummaryItem label="所定労働時間"><Duration minutes={totals.prescribed_work_minutes} /></SummaryItem>
        <SummaryItem label="法定内残業時間"><Duration minutes={totals.statutory_within_overtime_minutes} /></SummaryItem>
        <SummaryItem label="法定外残業時間"><Duration minutes={totals.statutory_excess_overtime_minutes} /></SummaryItem>
        {statutoryExcessOver60hMinutes !== undefined && (
          <SummaryItem label="うち月60時間超"><Duration minutes={statutoryExcessOver60hMinutes} /></SummaryItem>
        )}
        <SummaryItem label="法定休日労働時間"><Duration minutes={totals.legal_holiday_work_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜所定労働時間"><Duration minutes={totals.late_night_prescribed_work_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定内残業時間"><Duration minutes={totals.late_night_statutory_within_overtime_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定外残業時間"><Duration minutes={totals.late_night_statutory_excess_overtime_minutes} /></SummaryItem>
        <SummaryItem label="うち深夜法定休日労働時間"><Duration minutes={totals.late_night_legal_holiday_work_minutes} /></SummaryItem>
      </dl>

      {hasLeaveTotals && (
        <dl className="mt-3 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-t border-border pt-3 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
          {absenceDays !== undefined && <SummaryItem label="欠勤日数">{absenceDays}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.absence_minutes) && <SummaryItem label="欠勤時間"><Duration minutes={totals.absence_minutes ?? 0} /></SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_days) && <SummaryItem label="有給日数">{totals.paid_leave_days ?? 0}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_minutes) && <SummaryItem label="有給時間(時間単位)"><Duration minutes={totals.paid_leave_minutes ?? 0} /></SummaryItem>}
          {specialLeaveDays !== undefined && <SummaryItem label="特別休暇日数">{specialLeaveDays}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.special_leave_minutes) && <SummaryItem label="特別休暇時間"><Duration minutes={totals.special_leave_minutes ?? 0} /></SummaryItem>}
        </dl>
      )}
    </section>
  )
}