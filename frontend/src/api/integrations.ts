import { apiFetch } from './client'
import type { ApplicationIntegration, IntegrationClientType, IntegrationScopeType } from './types'

export function fetchMyIntegrations(): Promise<ApplicationIntegration[]> {
  return apiFetch('/users/me/integrations')
}

export interface RegisterIntegrationInput {
  client_type: IntegrationClientType
  client_name: string
  purpose?: string
  scopes: IntegrationScopeType[]
}

export interface IntegrationTokenResult {
  integration: ApplicationIntegration
  token: string
}

export function registerIntegration(input: RegisterIntegrationInput): Promise<IntegrationTokenResult> {
  return apiFetch('/users/me/integrations', { method: 'POST', body: input })
}

export function reissueIntegrationToken(id: number): Promise<IntegrationTokenResult> {
  return apiFetch(`/users/me/integrations/${id}/reissue`, { method: 'POST' })
}

export function revokeIntegration(id: number): Promise<ApplicationIntegration> {
  return apiFetch(`/users/me/integrations/${id}/revoke`, { method: 'POST' })
}
