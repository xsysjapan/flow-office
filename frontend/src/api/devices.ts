import { apiFetch } from './client'
import type { Device, DeviceRoleType, DeviceScopeType, DeviceType, WorkLocationType } from './types'

export function fetchDevices(ownerType?: 'organization_shared' | 'personal'): Promise<Device[]> {
  return apiFetch('/devices', { query: { owner_type: ownerType } })
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

export interface IssuePairingCodeResult {
  device: Device
  pairing_code: string
}

export function issueDevicePairingCode(deviceId: number): Promise<IssuePairingCodeResult> {
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
