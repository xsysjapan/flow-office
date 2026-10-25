import { apiFetch } from './client'

export type ReadModelRebuildStatus = 'queued' | 'running' | 'succeeded' | 'failed'

export interface ReadModelRebuildTableSummary {
  table: string
  before: number
  after: number
  changed: boolean
}

export interface ReadModelRebuildSummary {
  dry_run: boolean
  tables: ReadModelRebuildTableSummary[]
  changed_tables: number
}

export interface ReadModelRebuildRun {
  id: string
  dry_run: boolean
  status: ReadModelRebuildStatus
  requested_by: { id: string; name: string } | null
  requested_at: string
  started_at: string | null
  finished_at: string | null
  error_message: string | null
  /** 一覧では常にnull。1件取得で入る。 */
  summary: ReadModelRebuildSummary | null
}

/** 作り直しの履歴(直近20件。summaryは含まれない)。 */
export function fetchReadModelRebuilds(): Promise<{ data: ReadModelRebuildRun[] }> {
  return apiFetch('/admin/read-model-rebuilds')
}

/** 1件(summary付き)。 */
export function fetchReadModelRebuild(id: string): Promise<{ data: ReadModelRebuildRun }> {
  return apiFetch(`/admin/read-model-rebuilds/${encodeURIComponent(id)}`)
}

/** 作り直しの依頼。実行は非同期で、APIは受付(202)を返す。実行中・待機中なら409。 */
export function requestReadModelRebuild(dryRun: boolean): Promise<{ data: ReadModelRebuildRun }> {
  return apiFetch('/admin/read-model-rebuilds', { method: 'POST', body: { dry_run: dryRun } })
}

/** メンテナンス中か(認証不要。包みなし)。 */
export function fetchMaintenanceStatus(): Promise<{ maintenance: boolean }> {
  return apiFetch('/maintenance-status')
}
