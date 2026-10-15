import { describe, expect, it } from 'vitest'
import type { AttendanceDay } from '../api/types'
import { dayWarnings } from './attendanceDayWarnings'

const baseDay: AttendanceDay = {
  id: 'day-1',
  user_id: '11111111-1111-1111-1111-111111111111',
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
    late_night_prescribed_holiday_work_minutes: 0,
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

  it('does not warn of 打刻漏れ for a past full-day leave (全休)', () => {
    const day: AttendanceDay = {
      ...baseDay,
      status: 'not_started',
      breaks: [],
      calculation: null,
      actual_start_at: null,
      actual_end_at: null,
      leaves: [{ leave_kind: 'paid', unit: 'full', hours: null, minutes: null, special_leave_type_id: null, request_id: 'r1', workflow_request_id: 'w1', request_status: 'approved' }],
    }
    expect(dayWarnings('2026-07-01', day, '2026-07-06')).toEqual([])
  })

  it('does not warn of 打刻漏れ when both am_half and pm_half leaves cover the past day (種類を問わず全休扱い)', () => {
    const leave = (unit: 'am_half' | 'pm_half', leave_kind: 'paid' | 'compensatory') => ({
      leave_kind,
      unit,
      hours: null,
      minutes: null,
      special_leave_type_id: null,
      request_id: `r-${unit}`,
      workflow_request_id: null,
      request_status: 'submitted' as const,
    })
    const day: AttendanceDay = {
      ...baseDay,
      status: 'not_started',
      breaks: [],
      calculation: null,
      actual_start_at: null,
      actual_end_at: null,
      leaves: [leave('am_half', 'paid'), leave('pm_half', 'compensatory')],
    }
    expect(dayWarnings('2026-07-01', day, '2026-07-06')).toEqual([])
  })

  it('still warns of 打刻漏れ for a past half-day leave only (半休だけでは全休扱いしない)', () => {
    const day: AttendanceDay = {
      ...baseDay,
      status: 'not_started',
      breaks: [],
      calculation: null,
      actual_start_at: null,
      actual_end_at: null,
      leaves: [{ leave_kind: 'paid', unit: 'am_half', hours: null, minutes: null, special_leave_type_id: null, request_id: 'r1', workflow_request_id: null, request_status: 'approved' }],
    }
    expect(dayWarnings('2026-07-01', day, '2026-07-06')).toEqual(['打刻漏れ'])
  })

  it('warns of 欠勤 when the daily calculation reports absence minutes, without also warning 打刻漏れ', () => {
    const day: AttendanceDay = {
      ...baseDay,
      status: 'not_started',
      calculation: { ...baseDay.calculation!, absence_minutes: 480 },
    }
    expect(dayWarnings('2026-07-01', day, '2026-07-06')).toEqual(['欠勤'])
  })
})
