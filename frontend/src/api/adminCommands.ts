import { apiFetch } from './client'
import type { AdminCommandDefinition, AdminCommandRun, Paginated } from './types'

export function fetchAdminCommands(): Promise<{ data: AdminCommandDefinition[] }> {
  return apiFetch('/admin/commands')
}

export function fetchAdminCommandRuns(): Promise<Paginated<AdminCommandRun>> {
  return apiFetch('/admin/command-runs')
}

export function runAdminCommand(command: string, parameters: Record<string, unknown>): Promise<{ data: AdminCommandRun }> {
  return apiFetch(`/admin/commands/${encodeURIComponent(command)}/runs`, { method: 'POST', body: { parameters } })
}
