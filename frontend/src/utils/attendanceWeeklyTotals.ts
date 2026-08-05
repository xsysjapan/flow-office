import type { AttendanceDay } from '../api/types'
import type { AttendanceSpecialLeaveBreakdownItem } from '../components/AttendanceCalculationSummary/AttendanceCalculationSummary'

const WEEKLY_TOTAL_FIELDS = [
  'work_minutes',
  'prescribed_work_minutes',
  'statutory_within_overtime_minutes',
  'statutory_excess_overtime_minutes',
  'late_night_prescribed_work_minutes',
  'late_night_statutory_within_overtime_minutes',
  'late_night_statutory_excess_overtime_minutes',
  'legal_holiday_work_minutes',
  'late_night_legal_holiday_work_minutes',
  'prescribed_holiday_work_minutes',
  'late_night_prescribed_holiday_work_minutes',
  'absence_minutes',
  'paid_leave_days',
  'paid_leave_minutes',
  'special_leave_days',
  'special_leave_minutes',
] as const

export type WeeklyAttendanceTotals = Record<(typeof WEEKLY_TOTAL_FIELDS)[number], number>

function zeroWeeklyTotals(): WeeklyAttendanceTotals {
  return {
    work_minutes: 0,
    prescribed_work_minutes: 0,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    legal_holiday_work_minutes: 0,
    late_night_legal_holiday_work_minutes: 0,
    prescribed_holiday_work_minutes: 0,
    late_night_prescribed_holiday_work_minutes: 0,
    absence_minutes: 0,
    paid_leave_days: 0,
    paid_leave_minutes: 0,
    special_leave_days: 0,
    special_leave_minutes: 0,
  }
}

/** 週次一覧に含まれる日々のspecial_leave_usages(AttendanceDayResource参照)を
 *  special_leave_type_idごとにグルーピングする。バックエンドのMonthlyOvertimeCalculator.
 *  calculateSpecialLeaveBreakdownと同じ考え方(全休・半休はused_daysを、時間単位はminutesを
 *  合算する)を、クライアントサイドで週次分に対して行う。 */
export function specialLeaveTypeBreakdown(days: AttendanceDay[]): AttendanceSpecialLeaveBreakdownItem[] {
  const byType = new Map<string, AttendanceSpecialLeaveBreakdownItem>()

  for (const day of days) {
    for (const usage of day.special_leave_usages ?? []) {
      const existing = byType.get(usage.special_leave_type_id) ?? {
        special_leave_type_id: usage.special_leave_type_id,
        special_leave_type_name: usage.special_leave_type_name,
        days: 0,
        minutes: 0,
      }

      if (usage.usage_type === 'hourly') {
        existing.minutes += usage.used_minutes ?? 0
      } else {
        existing.days += usage.used_days
      }

      byType.set(usage.special_leave_type_id, existing)
    }
  }

  return Array.from(byType.values())
}

/** 週次・日次一覧(7日分など)の合計。終日欠勤は、その日の欠勤時間が所定労働時間以上に
 *  なった日を1日と数える(月次集計と同じ基準、docs/07-usecases-attendance.md参照)。
 *  有給・特別休暇は全休・半休の合計(attendance_days.work_type由来)をそのまま合算する
 *  (paid_leave_days/special_leave_daysは既に日単位の値のため、しきい値判定は不要)。 */
export function weeklyAttendanceTotals(days: AttendanceDay[]): {
  totals: WeeklyAttendanceTotals
  absenceDays: number
  specialLeaveDays: number
  specialLeaveBreakdown: AttendanceSpecialLeaveBreakdownItem[]
} {
  const absenceDays = days.reduce((count, day) => {
    const calculation = day.calculation
    if (!calculation || calculation.prescribed_work_minutes <= 0) return count

    return (calculation.absence_minutes ?? 0) >= calculation.prescribed_work_minutes ? count + 1 : count
  }, 0)

  const totals = days.reduce((sum, day) => {
    if (!day.calculation) return sum

    for (const field of WEEKLY_TOTAL_FIELDS) {
      sum[field] += day.calculation[field] ?? 0
    }

    return sum
  }, zeroWeeklyTotals())

  return { totals, absenceDays, specialLeaveDays: totals.special_leave_days, specialLeaveBreakdown: specialLeaveTypeBreakdown(days) }
}
