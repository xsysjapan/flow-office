import { describe, expect, it } from 'vitest'
import type { AttendanceDayLeave } from '../api/types'
import { isFullDayLeave } from './attendanceLeaves'

function leave(
  unit: AttendanceDayLeave['unit'],
  leave_kind: AttendanceDayLeave['leave_kind'] = 'paid',
): AttendanceDayLeave {
  return {
    leave_kind,
    unit,
    hours: unit === 'hourly' ? 2 : null,
    minutes: unit === 'hourly' ? 120 : null,
    special_leave_type_id: leave_kind === 'special' ? 1 : null,
    request_id: `request-${leave_kind}-${unit}`,
    workflow_request_id: null,
    request_status: 'approved',
  }
}

describe('isFullDayLeave', () => {
  it('is false when there are no leaves', () => {
    expect(isFullDayLeave(undefined)).toBe(false)
    expect(isFullDayLeave([])).toBe(false)
  })

  it('is true for a full-day leave of any kind', () => {
    expect(isFullDayLeave([leave('full', 'paid')])).toBe(true)
    expect(isFullDayLeave([leave('full', 'special')])).toBe(true)
    expect(isFullDayLeave([leave('full', 'compensatory')])).toBe(true)
  })

  it('is false for a single half-day leave', () => {
    expect(isFullDayLeave([leave('am_half')])).toBe(false)
    expect(isFullDayLeave([leave('pm_half')])).toBe(false)
  })

  it('is true when am_half and pm_half are both present, regardless of kind', () => {
    expect(isFullDayLeave([leave('am_half', 'paid'), leave('pm_half', 'compensatory')])).toBe(true)
    expect(isFullDayLeave([leave('pm_half', 'special'), leave('am_half', 'special')])).toBe(true)
  })

  it('is false for hourly leave only', () => {
    expect(isFullDayLeave([leave('hourly')])).toBe(false)
  })

  it('is false for an am_half plus hourly combination', () => {
    expect(isFullDayLeave([leave('am_half'), leave('hourly', 'special')])).toBe(false)
  })
})
