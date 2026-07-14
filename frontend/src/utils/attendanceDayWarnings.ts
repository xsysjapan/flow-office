import type { AttendanceDay } from '../api/types'

/** 週次・月次画面で各日の行に表示する注意バッジ(未入力・打刻漏れ・休憩不足・長時間労働)を算出する。 */
export function dayWarnings(date: string, day: AttendanceDay | undefined, today: string): string[] {
  const warnings: string[] = []
  const isPast = date < today

  // 記録が無い日は行の状態バッジ自体が「未入力」を表示するため、ここでは重複させない。
  if (!day) return warnings

  if (isPast && day.status !== 'clocked_out') warnings.push('打刻漏れ')

  if (day.calculation) {
    const workedMinutes = day.calculation.actual_work_minutes
    const breakMinutes = day.breaks.reduce((sum, b) => {
      if (!b.break_start_at || !b.break_end_at) return sum
      return sum + (new Date(b.break_end_at).getTime() - new Date(b.break_start_at).getTime()) / 60000
    }, 0)

    if (workedMinutes > 480 && breakMinutes < 60) warnings.push('休憩不足')
    else if (workedMinutes > 360 && breakMinutes < 45) warnings.push('休憩不足')

    if (workedMinutes > 600) warnings.push('長時間労働')
  }

  return warnings
}
