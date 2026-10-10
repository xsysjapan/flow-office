import { describe, expect, it } from 'vitest'
import type { AttendanceDay, AttendanceDayLeave } from '../api/types'
import { specialLeaveTypeBreakdown, weeklyAttendanceTotals } from './attendanceWeeklyTotals'

function leave(overrides: Partial<AttendanceDayLeave>): AttendanceDayLeave {
  return {
    leave_kind: 'special',
    unit: 'full',
    hours: null,
    minutes: null,
    special_leave_type_id: 1,
    request_id: 'request-1',
    workflow_request_id: null,
    request_status: 'approved',
    ...overrides,
  }
}

function day(work_date: string, leaves: AttendanceDayLeave[]): AttendanceDay {
  return {
    id: `day-${work_date}`,
    user_id: '11111111-1111-1111-1111-111111111111',
    work_date,
    status: 'not_started',
    actual_start_at: null,
    actual_end_at: null,
    work_type: null,
    note: null,
    is_locked: false,
    breaks: [],
    calculation: null,
    leaves,
  }
}

describe('specialLeaveTypeBreakdown', () => {
  it('counts full days as 1 and half days as 0.5 per special leave type', () => {
    const result = specialLeaveTypeBreakdown(
      [
        day('2026-08-10', [leave({ unit: 'full' })]),
        day('2026-08-11', [leave({ unit: 'am_half' })]),
        day('2026-08-12', [leave({ unit: 'pm_half' })]),
      ],
      new Map([[1, '慶弔休暇']]),
    )

    expect(result).toEqual([
      { special_leave_type_id: '1', special_leave_type_name: '慶弔休暇', days: 2, minutes: 0 },
    ])
  })

  it('sums the minutes of hourly special leave separately from days', () => {
    const result = specialLeaveTypeBreakdown(
      [
        day('2026-08-10', [leave({ unit: 'hourly', hours: 1.5, minutes: 90 })]),
        day('2026-08-11', [leave({ unit: 'hourly', hours: 0.5, minutes: 30 })]),
      ],
      new Map([[1, '慶弔休暇']]),
    )

    expect(result).toEqual([
      { special_leave_type_id: '1', special_leave_type_name: '慶弔休暇', days: 0, minutes: 120 },
    ])
  })

  it('groups by type and ignores paid and compensatory leave', () => {
    const result = specialLeaveTypeBreakdown(
      [
        day('2026-08-10', [
          leave({ special_leave_type_id: 1, unit: 'full' }),
          leave({ special_leave_type_id: 2, unit: 'full' }),
          leave({ leave_kind: 'paid', special_leave_type_id: null, unit: 'full' }),
          leave({ leave_kind: 'compensatory', special_leave_type_id: null, unit: 'full' }),
        ]),
      ],
      new Map([
        [1, '慶弔休暇'],
        [2, '忌引'],
      ]),
    )

    expect(result.map((item) => [item.special_leave_type_id, item.special_leave_type_name, item.days])).toEqual([
      ['1', '慶弔休暇', 1],
      ['2', '忌引', 1],
    ])
  })

  it('falls back to 種別#ID when the type name is unknown', () => {
    const result = specialLeaveTypeBreakdown([day('2026-08-10', [leave({ special_leave_type_id: 7, unit: 'full' })])])

    expect(result[0].special_leave_type_name).toBe('種別#7')
  })
})

describe('weeklyAttendanceTotals', () => {
  it('passes the type names through to the special leave breakdown', () => {
    const result = weeklyAttendanceTotals(
      [day('2026-08-10', [leave({ unit: 'full' })])],
      new Map([[1, '慶弔休暇']]),
    )

    expect(result.specialLeaveBreakdown).toEqual([
      { special_leave_type_id: '1', special_leave_type_name: '慶弔休暇', days: 1, minutes: 0 },
    ])
  })
})
