import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { downloadAttendanceExcel } from './exports'

describe('downloadAttendanceExcel', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    Object.defineProperty(URL, 'createObjectURL', { value: vi.fn(() => 'blob:test'), configurable: true })
    Object.defineProperty(URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    vi.mocked(fetch).mockResolvedValue(
      new Response('xlsx', {
        status: 200,
        headers: { 'content-type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  function requestedUrl(): URL {
    const [input] = vi.mocked(fetch).mock.calls[0]
    return new URL(String(input))
  }

  it('adds include_submitted=1 when include_submitted is true', async () => {
    await downloadAttendanceExcel({ year_month: ['2026-10'], user_id: ['user-1'], include_submitted: true })

    expect(requestedUrl().searchParams.get('include_submitted')).toBe('1')
    expect(requestedUrl().searchParams.getAll('year_month[]')).toEqual(['2026-10'])
    expect(requestedUrl().searchParams.getAll('user_id[]')).toEqual(['user-1'])
  })

  it('does not add include_submitted when it is omitted', async () => {
    await downloadAttendanceExcel({ year_month: ['2026-10'] })

    expect(requestedUrl().searchParams.has('include_submitted')).toBe(false)
  })

  it('does not add include_submitted when it is false', async () => {
    await downloadAttendanceExcel({ year_month: ['2026-10'], include_submitted: false })

    expect(requestedUrl().searchParams.has('include_submitted')).toBe(false)
  })
})
