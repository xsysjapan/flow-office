import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError, type ApiFetchOptions, clearToken, getToken, MAINTENANCE_EVENT, setToken } from './client'

describe('token storage', () => {
  afterEach(() => {
    clearToken()
  })

  it('stores and retrieves the token', () => {
    expect(getToken()).toBeNull()
    setToken('abc123')
    expect(getToken()).toBe('abc123')
    clearToken()
    expect(getToken()).toBeNull()
  })
})

describe('apiFetch', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    clearToken()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends an Authorization header when a token is stored', async () => {
    setToken('my-token')
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ id: 1 }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
    )

    await apiFetch('/me')

    const [, init] = vi.mocked(fetch).mock.calls[0]
    const headers = init?.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer my-token')
  })

  it('serializes the body as JSON and sets Content-Type', async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
    )

    await apiFetch('/workflow-requests', { method: 'POST', body: { title: 'test' } })

    const [, init] = vi.mocked(fetch).mock.calls[0]
    expect(init?.body).toBe(JSON.stringify({ title: 'test' }))
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('throws an ApiError with the message and validation errors on failure', async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(
        JSON.stringify({ message: 'The title field is required.', errors: { title: ['required'] } }),
        { status: 422, headers: { 'content-type': 'application/json' } },
      ),
    )

    await expect(apiFetch('/workflow-requests', { method: 'POST', body: {} })).rejects.toMatchObject({
      status: 422,
      message: 'The title field is required.',
      errors: { title: ['required'] },
    })
  })

  it('is an instance of ApiError', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 500 }))

    try {
      await apiFetch('/boom')
      expect.fail('expected apiFetch to throw')
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError)
    }
  })

  it('marks a 503 with code=maintenance as a maintenance error and notifies the window', async () => {
    const listener = vi.fn()
    window.addEventListener(MAINTENANCE_EVENT, listener)
    vi.mocked(fetch).mockResolvedValue(
      new Response(JSON.stringify({ code: 'maintenance', message: 'メンテナンス中です。' }), {
        status: 503,
        headers: { 'content-type': 'application/json' },
      }),
    )

    try {
      await apiFetch('/attendance/today')
      expect.fail('expected apiFetch to throw')
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).isMaintenance).toBe(true)
      expect((error as ApiError).message).toBe('メンテナンス中です。')
    } finally {
      window.removeEventListener(MAINTENANCE_EVENT, listener)
    }
    expect(listener).toHaveBeenCalledTimes(1)
    expect((listener.mock.calls[0][0] as CustomEvent<string>).detail).toBe('メンテナンス中です。')
  })

  it('does not treat other 503 or other codes as maintenance', async () => {
    const listener = vi.fn()
    window.addEventListener(MAINTENANCE_EVENT, listener)
    vi.mocked(fetch).mockResolvedValueOnce(new Response('down', { status: 503 }))
    vi.mocked(fetch).mockResolvedValueOnce(
      new Response(JSON.stringify({ code: 'other', message: 'x' }), { status: 503, headers: { 'content-type': 'application/json' } }),
    )
    vi.mocked(fetch).mockResolvedValueOnce(
      new Response(JSON.stringify({ code: 'maintenance', message: 'x' }), { status: 500, headers: { 'content-type': 'application/json' } }),
    )

    for (let i = 0; i < 3; i++) {
      await expect(apiFetch('/x')).rejects.toMatchObject({ isMaintenance: false })
    }
    window.removeEventListener(MAINTENANCE_EVENT, listener)
    expect(listener).not.toHaveBeenCalled()
  })

  it('returns undefined for a 204 No Content response', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 204 }))

    await expect(apiFetch('/logout', { method: 'POST' })).resolves.toBeUndefined()
  })

  describe('query serialization', () => {
    async function requestedUrl(query: ApiFetchOptions['query']): Promise<URL> {
      vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 204 }))
      await apiFetch('/users', { query })
      return new URL(String(vi.mocked(fetch).mock.calls[0][0]))
    }

    it('sends true as 1 and false as 0', async () => {
      const url = await requestedUrl({ external_unlinked: true, with_trashed: false })
      expect(url.searchParams.get('external_unlinked')).toBe('1')
      expect(url.searchParams.get('with_trashed')).toBe('0')
      expect(url.search).not.toContain('true')
      expect(url.search).not.toContain('false')
    })

    it('converts booleans inside arrays', async () => {
      const url = await requestedUrl({ flags: [true, false] })
      expect(url.searchParams.getAll('flags[]')).toEqual(['1', '0'])
    })

    it('does not send undefined values', async () => {
      const url = await requestedUrl({ a: undefined, b: 'x' })
      expect(url.searchParams.has('a')).toBe(false)
      expect(url.searchParams.get('b')).toBe('x')
    })

    it('keeps strings and numbers as they are', async () => {
      const url = await requestedUrl({ q: 'abc', page: 2 })
      expect(url.searchParams.get('q')).toBe('abc')
      expect(url.searchParams.get('page')).toBe('2')
    })
  })

  it('exposes code and items of a bulk_operation_rejected response', async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response(
        JSON.stringify({
          message: '選んだ対象のうち、処理できないものがあります。',
          code: 'bulk_operation_rejected',
          errors: { 'items.3': ['理由C'] },
          items: [{ id: 3, label: 'タスクC', message: '理由C' }, { id: 'x', message: '理由X' }],
        }),
        { status: 422, headers: { 'content-type': 'application/json' } },
      ),
    )

    const error = await apiFetch('/bulk', { method: 'POST', body: {} }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).code).toBe('bulk_operation_rejected')
    expect((error as ApiError).items).toEqual([
      { id: '3', label: 'タスクC', message: '理由C' },
      { id: 'x', label: undefined, message: '理由X' },
    ])
  })
})
