import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchShiftAssignments } from './employeeShiftAssignments'

describe('fetchShiftAssignments', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    localStorage.clear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns the data array from the effective-schedule response envelope', async () => {
    const entry = {
      id: null,
      user_id: 'user-1',
      work_date: '2026-07-05',
      work_style_id: 'style-1',
      shift_pattern_id: null,
      day_type: 'legal_holiday',
      is_working_day: false,
      is_legal_holiday: true,
      is_company_holiday: false,
      schedule_state: 'OFF',
      planned_start_at: null,
      planned_end_at: null,
      planned_break_minutes: 0,
      planned_break_start_at: null,
      planned_break_end_at: null,
      is_published: true,
      is_manually_overridden: false,
      schedule_source: 'company_calendar',
      provisional: false,
    }
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ data: [entry], provisional: false }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    }))

    await expect(fetchShiftAssignments('user-1', '2026-07-01', '2026-07-07')).resolves.toEqual([entry])
  })
})
