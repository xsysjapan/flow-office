import { apiFetch } from './client'
import type { SystemSettings } from './types'

export function fetchSystemSettings(): Promise<SystemSettings> {
  return apiFetch('/system-settings')
}

export function updateSystemSettings(input: SystemSettings): Promise<SystemSettings> {
  return apiFetch('/system-settings', { method: 'PUT', body: input })
}
