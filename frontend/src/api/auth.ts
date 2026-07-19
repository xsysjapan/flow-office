import { apiFetch } from './client'
import type { User } from './types'

export function fetchMicrosoftRedirectUrl(): Promise<{ url: string }> {
  return apiFetch('/auth/microsoft/redirect')
}

export function exchangeCodeForToken(code: string): Promise<{ token: string; user: User }> {
  return apiFetch('/auth/token', { method: 'POST', body: { code } })
}

/** SSOを設定しなかった場合のローカルパスワードログイン(docs/06-usecases-auth.md UC-000)。 */
export function localLogin(email: string, password: string): Promise<{ token: string; user: User }> {
  return apiFetch('/auth/local-login', { method: 'POST', body: { email, password } })
}

export function fetchCurrentUser(): Promise<User> {
  return apiFetch('/auth/me')
}

export function logout(): Promise<void> {
  return apiFetch('/auth/logout', { method: 'POST' })
}
