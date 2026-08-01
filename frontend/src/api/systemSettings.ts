import { apiFetch } from './client'
import type { SystemSettings, UpdateSystemSettingsInput } from './types'

export function fetchSystemSettings(): Promise<SystemSettings> {
  return apiFetch('/admin/system-settings')
}

export function updateSystemSettings(input: UpdateSystemSettingsInput): Promise<SystemSettings> {
  return apiFetch('/admin/system-settings', { method: 'PUT', body: input })
}
