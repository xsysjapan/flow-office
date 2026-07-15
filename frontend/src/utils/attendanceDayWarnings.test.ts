import { describe, expect, it } from 'vitest'
import type { AttendanceDay } from '../api/types'
import { dayWarnings } from './attendanceDayWarnings'

const baseDay: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: '2026-07-06',
  status: 'clocked_out',
  actual_start_at: '2026-07-06T09:00:00+09:00',
  actual_end_at: '2026-07-06T18:00:00+09:00',
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [{ id: 1, break_start_at: '2026-07-06T12:00:00+09:00', break_end_at: '2026-07-06T12:45:00+09:00' }],
  calculation: {
    planned_work_minutes: 480,
    work_minutes: 480,
    prescribed_work_minutes: 480,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_work_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    legal_holiday_work_minutes: 0,
    prescribed_holiday_work_minutes: 0,
    late_night_legal_holiday_work_minutes: 0,
    core_time_violation: false,
    is_manually_adjusted: false,
  },
}

describe('dayWarnings', () => {
  it('has no extra warnings for a past date with no record (the row status badge already shows 未入力)', () => {
    expect(dayWarnings('2026-07-01', undefined, '2026-07-06')).toEqual([])
  })

  it('does not warn for a future date with no record', () => {
    expect(dayWarnings('2026-07-10', undefined, '2026-07-06')).toEqual([])
  })

  it('warns of 打刻漏れ for a past day that never clocked out', () => {
    const day: AttendanceDay = { ...baseDay, status: 'working' }
    expect(dayWarnings('2026-07-01', day, '2026-07-06')).toContain('打刻漏れ')
  })

  it('does not warn for a fully clocked-out past day', () => {
    expect(dayWarnings('2026-07-01', baseDay, '2026-07-06')).toEqual([])
  })

  it('warns of 休憩不足 when worked over 8 hours with less than 60 minutes of break', () => {
    const day: AttendanceDay = {
      ...baseDay,
      breaks: [],
      calculation: { ...baseDay.calculation!, work_minutes: 500 },
    }
    expect(dayWarnings('2026-07-06', day, '2026-07-06')).toContain('休憩不足')
  })

  it('warns of 長時間労働 when worked over 600 minutes', () => {
    const day: AttendanceDay = {
      ...baseDay,
      calculation: { ...baseDay.calculation!, work_minutes: 650 },
    }
    expect(dayWarnings('2026-07-06', day, '2026-07-06')).toContain('長時間労働')
  })
})
