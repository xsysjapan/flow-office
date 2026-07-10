import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError, clearToken, getToken, setToken } from './client'

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

  it('returns undefined for a 204 No Content response', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 204 }))

    await expect(apiFetch('/logout', { method: 'POST' })).resolves.toBeUndefined()
  })
})
