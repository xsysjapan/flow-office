import { apiFetch, getToken } from './client'
import type { Paginated, StoredEvent } from './types'

export interface AuditLogFilters {
  aggregate_type?: string
  aggregate_id?: string
  event_type?: string
  user_id?: string
  from?: string
  to?: string
  [key: string]: string | number | boolean | undefined
}

export function fetchAuditLog(filters: AuditLogFilters = {}): Promise<Paginated<StoredEvent>> {
  return apiFetch('/audit-log', { query: filters })
}

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

/**
 * CSVはブラウザのダウンロードとして扱うため、fetchでBlobを取得してから
 * クリックイベントを合成する(apiFetchのJSON前提の処理には乗せない)。
 */
export async function downloadAuditLogCsv(filters: AuditLogFilters = {}): Promise<void> {
  const url = new URL('audit-log/export', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined) url.searchParams.set(key, String(value))
  }

  const token = getToken()
  const response = await fetch(url.toString(), {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })

  if (!response.ok) {
    throw new Error('監査ログCSVの取得に失敗しました。')
  }

  const blob = await response.blob()
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = 'audit_log.csv'
  link.click()
  URL.revokeObjectURL(objectUrl)
}
