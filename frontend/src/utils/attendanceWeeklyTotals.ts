import type { AttendanceDay } from '../api/types'
import type { AttendanceSpecialLeaveBreakdownItem } from '../components/AttendanceCalculationSummary/AttendanceCalculationSummary'

const WEEKLY_TOTAL_FIELDS = [
  'work_minutes',
  'prescribed_work_minutes',
  'prescribed_statutory_within_work_minutes',
  'non_prescribed_statutory_within_work_minutes',
  'prescribed_statutory_excess_work_minutes',
  'non_prescribed_statutory_excess_work_minutes',
  'statutory_within_overtime_minutes',
  'statutory_excess_overtime_minutes',
  'late_night_prescribed_work_minutes',
  'late_night_statutory_within_overtime_minutes',
  'late_night_statutory_excess_overtime_minutes',
  'late_night_prescribed_statutory_within_work_minutes',
  'late_night_non_prescribed_statutory_within_work_minutes',
  'late_night_prescribed_statutory_excess_work_minutes',
  'late_night_non_prescribed_statutory_excess_work_minutes',
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
    prescribed_statutory_within_work_minutes: 0,
    non_prescribed_statutory_within_work_minutes: 0,
    prescribed_statutory_excess_work_minutes: 0,
    non_prescribed_statutory_excess_work_minutes: 0,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    late_night_prescribed_statutory_within_work_minutes: 0,
    late_night_non_prescribed_statutory_within_work_minutes: 0,
    late_night_prescribed_statutory_excess_work_minutes: 0,
    late_night_non_prescribed_statutory_excess_work_minutes: 0,
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

/** 週次一覧に含まれる日々の休暇(`leaves`の特別休暇の行)を special_leave_type_idごとに
 *  グルーピングする。バックエンドのMonthlyOvertimeCalculator.calculateSpecialLeaveBreakdownと
 *  同じ考え方(全休は1日、半休は0.5日を数え、時間休はminutesを合算する)を、クライアントサイドで
 *  週次分に対して行う。種別名は呼び出し側が渡す(`leaves`は種別名を持たない)。未指定の種別は
 *  「種別#ID」と表示する。 */
export function specialLeaveTypeBreakdown(
  days: AttendanceDay[],
  specialLeaveTypeNames: ReadonlyMap<number, string> = new Map(),
): AttendanceSpecialLeaveBreakdownItem[] {
  const byType = new Map<string, AttendanceSpecialLeaveBreakdownItem>()

  for (const day of days) {
    for (const leave of day.leaves ?? []) {
      if (leave.leave_kind !== 'special' || leave.special_leave_type_id === null) continue

      const typeId = String(leave.special_leave_type_id)
      const existing = byType.get(typeId) ?? {
        special_leave_type_id: typeId,
        special_leave_type_name: specialLeaveTypeNames.get(leave.special_leave_type_id) ?? `種別#${typeId}`,
        days: 0,
        minutes: 0,
      }

      if (leave.unit === 'hourly') {
        existing.minutes += leave.minutes ?? 0
      } else {
        existing.days += leave.unit === 'full' ? 1 : 0.5
      }

      byType.set(typeId, existing)
    }
  }

  return Array.from(byType.values())
}

/** 週次・日次一覧(7日分など)の合計。終日欠勤は、その日の欠勤時間が所定労働時間以上に
 *  なった日を1日と数える(月次集計と同じ基準、docs/07-usecases-attendance.md参照)。
 *  有給・特別休暇は全休・半休の合計(休暇ビュー`leaves`由来の日数)をそのまま合算する
 *  (paid_leave_days/special_leave_daysは既に日単位の値のため、しきい値判定は不要)。 */
export function weeklyAttendanceTotals(
  days: AttendanceDay[],
  specialLeaveTypeNames: ReadonlyMap<number, string> = new Map(),
): {
  totals: WeeklyAttendanceTotals
  absenceDays: number
  workedDays: number
  specialLeaveDays: number
  specialLeaveBreakdown: AttendanceSpecialLeaveBreakdownItem[]
} {
  const absenceDays = days.reduce((count, day) => {
    const calculation = day.calculation
    if (!calculation || calculation.prescribed_work_minutes <= 0) return count

    return (calculation.absence_minutes ?? 0) >= calculation.prescribed_work_minutes ? count + 1 : count
  }, 0)

  // 労働日数(月次のworked_daysと同じ基準: work_minutes > 0の日を数える)。
  const workedDays = days.reduce((count, day) => {
    return (day.calculation?.work_minutes ?? 0) > 0 ? count + 1 : count
  }, 0)

  const totals = days.reduce((sum, day) => {
    if (!day.calculation) return sum

    for (const field of WEEKLY_TOTAL_FIELDS) {
      sum[field] += day.calculation[field] ?? 0
    }

    return sum
  }, zeroWeeklyTotals())

  return {
    totals,
    absenceDays,
    workedDays,
    specialLeaveDays: totals.special_leave_days,
    specialLeaveBreakdown: specialLeaveTypeBreakdown(days, specialLeaveTypeNames),
  }
}
