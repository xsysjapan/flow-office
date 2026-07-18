export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
const TOKEN_STORAGE_KEY = 'flow-office.token'

export class ApiError extends Error {
  readonly status: number
  readonly errors?: Record<string, string[]>

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

export interface ApiFetchOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
  query?: Record<string, string | number | boolean | undefined | Array<string | number>>
}

function buildUrl(path: string, query?: ApiFetchOptions['query']): string {
  const url = new URL(path.replace(/^\//, ''), `${API_BASE_URL.replace(/\/?$/, '/')}`)

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === undefined) continue
      if (Array.isArray(value)) {
        for (const item of value) url.searchParams.append(`${key}[]`, String(item))
      } else {
        url.searchParams.set(key, String(value))
      }
    }
  }

  return url.toString()
}

/**
 * バックエンドAPI(Sanctum Bearerトークン認証)への共通フェッチラッパー。
 * 単体テストでは fetch をモックして使う想定。
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { body, query, headers, ...rest } = options
  const token = getToken()

  const response = await fetch(buildUrl(path, query), {
    ...rest,
    headers: {
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const isJson = response.headers.get('content-type')?.includes('application/json')
  const payload = isJson ? await response.json() : await response.text()

  if (!response.ok) {
    const message = isJson && typeof payload === 'object' && payload && 'message' in payload
      ? String((payload as { message: unknown }).message)
      : response.statusText
    const errors = isJson && typeof payload === 'object' && payload && 'errors' in payload
      ? (payload as { errors: Record<string, string[]> }).errors
      : undefined

    throw new ApiError(response.status, message, errors)
  }

  return payload as T
}
