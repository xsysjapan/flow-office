import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError, MAINTENANCE_EVENT } from './client'
import { clearCorrectionPending, getCorrectionPending, isAllowedDuringCorrectionPending } from './correctionPending'

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })
}

const pending = () => json({ code: 'correction_pending', message: '補正待ちです。' }, 503)

describe('correction pending flag', () => {
  beforeEach(() => clearCorrectionPending())
  afterEach(() => vi.restoreAllMocks())

  it('recognises 503 correction_pending, records its message and does not fire the maintenance event', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(pending())
    const onMaintenance = vi.fn()
    window.addEventListener(MAINTENANCE_EVENT, onMaintenance)

    const error = await apiFetch('/attendance/days').catch((e: unknown) => e)
    window.removeEventListener(MAINTENANCE_EVENT, onMaintenance)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).isCorrectionPending).toBe(true)
    expect((error as ApiError).isMaintenance).toBe(false)
    expect(getCorrectionPending().message).toBe('補正待ちです。')
    expect(onMaintenance).not.toHaveBeenCalled()
  })

  it('does not treat a plain 503 or another status as correction pending', () => {
    expect(new ApiError(503, 'x').isCorrectionPending).toBe(false)
    expect(new ApiError(500, 'x', undefined, 'correction_pending').isCorrectionPending).toBe(false)
  })

  it('clears when a normal request succeeds, but not when an allowed request succeeds', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(pending())
    await apiFetch('/attendance/days').catch(() => undefined)

    fetchSpy.mockResolvedValueOnce(json({ id: 1 }))
    await apiFetch('/auth/me')
    fetchSpy.mockResolvedValueOnce(json({ data: [] }))
    await apiFetch('/admin/commands')
    expect(getCorrectionPending().message).toBe('補正待ちです。')

    fetchSpy.mockResolvedValueOnce(json({ data: [] }))
    await apiFetch('/attendance/days')
    expect(getCorrectionPending().message).toBeNull()
  })

  it('lists the requests the backend still allows', () => {
    expect(isAllowedDuringCorrectionPending('/auth/local-login', 'POST')).toBe(true)
    expect(isAllowedDuringCorrectionPending('auth/me')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/access/me')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/maintenance-status')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/admin/commands')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/admin/command-runs')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/admin/commands/foo/runs', 'POST')).toBe(true)
    expect(isAllowedDuringCorrectionPending('/admin/commands/foo/runs', 'GET')).toBe(false)
    expect(isAllowedDuringCorrectionPending('/admin/commands', 'POST')).toBe(false)
    expect(isAllowedDuringCorrectionPending('/attendance/days')).toBe(false)
  })
})
