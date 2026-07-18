import { apiFetch } from './client'
import type { AuthenticationKey, AuthenticationKeyType } from './types'

export function fetchAuthenticationKeysForUser(userId: number): Promise<AuthenticationKey[]> {
  return apiFetch(`/users/${userId}/authentication-keys`)
}

export interface IssueAuthenticationKeyInput {
  user_id?: number
  key_type: AuthenticationKeyType
  display_name: string
  raw_key_value: string
  valid_from?: string
  valid_until?: string
}

export function issueAuthenticationKey(input: IssueAuthenticationKeyInput): Promise<AuthenticationKey> {
  return apiFetch('/users/me/authentication-keys', { method: 'POST', body: input })
}

export function disableAuthenticationKey(id: number): Promise<AuthenticationKey> {
  return apiFetch(`/authentication-keys/${id}/disable`, { method: 'POST' })
}
