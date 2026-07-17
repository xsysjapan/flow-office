import type { AttendanceDay } from '../api/types'

const WEEKLY_TOTAL_FIELDS = [
  'prescribed_work_minutes',
  'statutory_within_overtime_minutes',
  'statutory_excess_overtime_minutes',
  'late_night_prescribed_work_minutes',
  'late_night_statutory_within_overtime_minutes',
  'late_night_statutory_excess_overtime_minutes',
  'legal_holiday_work_minutes',
  'late_night_legal_holiday_work_minutes',
  'absence_minutes',
  'special_leave_minutes',
  'paid_leave_days',
  'paid_leave_minutes',
] as const

export type WeeklyAttendanceTotals = Record<(typeof WEEKLY_TOTAL_FIELDS)[number], number>

function zeroWeeklyTotals(): WeeklyAttendanceTotals {
  return {
    prescribed_work_minutes: 0,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    legal_holiday_work_minutes: 0,
    late_night_legal_holiday_work_minutes: 0,
    absence_minutes: 0,
    special_leave_minutes: 0,
    paid_leave_days: 0,
    paid_leave_minutes: 0,
  }
}

/** 週次・日次一覧(7日分など)の合計。終日欠勤・終日特別休暇は、その日の欠勤/特別休暇時間が
 *  所定労働時間以上になった日を1日と数える(月次集計と同じ基準、docs/07-usecases-attendance.md参照)。 */
export function weeklyAttendanceTotals(days: AttendanceDay[]): {
  totals: WeeklyAttendanceTotals
  absenceDays: number
  specialLeaveDays: number
} {
  const leaveDays = days.reduce(
    (counts, day) => {
      const calculation = day.calculation
      if (!calculation || calculation.prescribed_work_minutes <= 0) return counts

      if ((calculation.absence_minutes ?? 0) >= calculation.prescribed_work_minutes) counts.absence += 1
      if ((calculation.special_leave_minutes ?? 0) >= calculation.prescribed_work_minutes) counts.specialLeave += 1

      return counts
    },
    { absence: 0, specialLeave: 0 },
  )

  const totals = days.reduce((sum, day) => {
    if (!day.calculation) return sum

    for (const field of WEEKLY_TOTAL_FIELDS) {
      sum[field] += day.calculation[field] ?? 0
    }

    return sum
  }, zeroWeeklyTotals())

  return { totals, absenceDays: leaveDays.absence, specialLeaveDays: leaveDays.specialLeave }
}
