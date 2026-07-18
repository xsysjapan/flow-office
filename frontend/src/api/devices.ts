import { apiFetch } from './client'
import type { Device, DeviceRoleType, DeviceScopeType, DeviceType, Paginated, WorkLocationType } from './types'

export interface FetchDevicesOptions {
  ownerType?: 'organization_shared' | 'personal'
  page?: number
  withTrashed?: boolean
}

export function fetchDevices({ ownerType, page, withTrashed }: FetchDevicesOptions = {}): Promise<Paginated<Device>> {
  return apiFetch('/devices', { query: { owner_type: ownerType, page, with_trashed: withTrashed } })
}

export interface RegisterDeviceInput {
  name: string
  device_type: DeviceType
  role_types: DeviceRoleType[]
  site_id?: string
  location_name?: string
  default_work_location_type?: WorkLocationType
  timezone?: string
  allow_offline?: boolean
  require_location?: boolean
  auto_detect_punch_type?: boolean
}

export function registerDevice(input: RegisterDeviceInput): Promise<Device> {
  return apiFetch('/devices', { method: 'POST', body: input })
}

export interface IssuePairingClaimResult {
  device: Device
  claim_token: string
}

// 一時ペアリングトークン(claim token)を発行する。管理者の認証済みトークンだけを
// 認可根拠にする(匿名のペアリングコード交換APIは持たない)。この一時トークンは
// device:claim-pairingのみのabilityを持つ短命なSanctumトークンで、QRコードとして
// 端末アプリへ渡す想定。
export function issueDevicePairingClaim(deviceId: number): Promise<IssuePairingClaimResult> {
  return apiFetch(`/devices/${deviceId}/pairing`, { method: 'POST' })
}

export function disableDevice(deviceId: number): Promise<Device> {
  return apiFetch(`/devices/${deviceId}/disable`, { method: 'POST' })
}

export function revokeDevice(deviceId: number, reason?: string): Promise<Device> {
  return apiFetch(`/devices/${deviceId}/revoke`, { method: 'POST', body: { reason } })
}

export function grantDeviceScope(deviceId: number, scope: DeviceScopeType): Promise<Device> {
  return apiFetch(`/devices/${deviceId}/scopes`, { method: 'POST', body: { scope } })
}

// UC-D005: 停止・失効済みの端末を一覧から論理削除する(管理者)。監査証跡は
// バックエンド側でstored_eventsに残り続けるため、フロントは削除操作の起点にすぎない。
export function deleteDevice(deviceId: number): Promise<void> {
  return apiFetch(`/devices/${deviceId}`, { method: 'DELETE' })
}
