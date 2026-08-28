import { apiFetch } from './client'
import type {
  ExternalIntegrationAuthType,
  ExternalIntegrationConnection,
  ExternalIntegrationProvider,
} from './types'

export function fetchExternalIntegrationConnections(): Promise<{ data: ExternalIntegrationConnection[] }> {
  return apiFetch('/admin/external-integration-connections')
}

export interface CreateExternalIntegrationConnectionInput {
  provider: ExternalIntegrationProvider
  name: string
  auth_type: ExternalIntegrationAuthType
  client_id?: string
  client_secret?: string
  api_key?: string
  external_office_id?: string
  custom_settings?: Record<string, unknown>
}

export function createExternalIntegrationConnection(
  input: CreateExternalIntegrationConnectionInput,
): Promise<ExternalIntegrationConnection> {
  return apiFetch('/admin/external-integration-connections', { method: 'POST', body: input })
}

// 部分更新。機密値(client_secret/api_key等)は空文字を送っても既存の暗号化値を上書きしない
// (バックエンド仕様)。呼び出し側は変更しない機密欄をそもそもキーに含めないこと。
export interface UpdateExternalIntegrationConnectionInput {
  name?: string
  auth_type?: ExternalIntegrationAuthType
  client_id?: string
  client_secret?: string
  api_key?: string
  external_office_id?: string
  custom_settings?: Record<string, unknown>
  enabled?: boolean
}

export function updateExternalIntegrationConnection(
  id: string,
  input: UpdateExternalIntegrationConnectionInput,
): Promise<ExternalIntegrationConnection> {
  return apiFetch(`/admin/external-integration-connections/${id}`, { method: 'PATCH', body: input })
}

export function deleteExternalIntegrationConnection(id: string): Promise<void> {
  return apiFetch(`/admin/external-integration-connections/${id}`, { method: 'DELETE' })
}

// freeeのOAuth2認可コードフロー(初回連携)。返ってきたurlへ画面遷移させると、
// freee側の認可後にバックエンドのcallbackがトークン交換まで行い、この画面へ戻ってくる。
export function getExternalIntegrationConnectionOAuthRedirectUrl(id: string): Promise<{ url: string }> {
  return apiFetch(`/admin/external-integration-connections/${id}/oauth/redirect-url`)
}
