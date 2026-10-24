import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchPaidLeaveScheduleAssessmentRunStatus } from './paidLeave'
import { clearToken } from './client'

describe('fetchPaidLeaveScheduleAssessmentRunStatus', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    clearToken()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns the status object without the { data } envelope', async () => {
    const status = {
      next_run_at: '2026-11-01T03:00:00+09:00',
      latest_run: null,
    }
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ data: status }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
    )

    await expect(fetchPaidLeaveScheduleAssessmentRunStatus()).resolves.toEqual(status)
  })
})
