import { apiFetch } from './client'
import type { ApplyScheduledGrantsResult, Paginated, PaidLeaveScheduleEntry } from './types'

/** 付与予定一覧のフィルタ(spec.md論点12の5フィルタタブに対応)。 */
export type PaidLeaveScheduleStatusFilter = 'all' | 'eligible' | 'not_eligible' | 'needs_review' | 'changed'

export interface FetchPaidLeaveScheduleEntriesOptions {
  status?: PaidLeaveScheduleStatusFilter
  userName?: string
  scheduledOnFrom?: string
  scheduledOnTo?: string
  page?: number
  perPage?: number
}

export function fetchPaidLeaveScheduleEntries(
  options: FetchPaidLeaveScheduleEntriesOptions = {},
): Promise<Paginated<PaidLeaveScheduleEntry>> {
  return apiFetch('/paid-leave/schedule-entries', {
    query: {
      status: options.status,
      user_name: options.userName,
      scheduled_on_from: options.scheduledOnFrom,
      scheduled_on_to: options.scheduledOnTo,
      page: options.page,
      per_page: options.perPage,
    },
  })
}

export function fetchPaidLeaveScheduleEntry(entryId: string): Promise<PaidLeaveScheduleEntry> {
  return apiFetch(`/paid-leave/schedule-entries/${entryId}`)
}

/** 依頼書§40「再判定」: 同一条件でAssessorを再実行する。 */
export function reassessPaidLeaveScheduleEntry(entryId: string): Promise<PaidLeaveScheduleEntry> {
  return apiFetch(`/paid-leave/schedule-entries/${entryId}/reassess`, { method: 'POST' })
}

export interface OverridePaidLeaveScheduleEntryInput {
  final_result: 'Eligible' | 'NotEligible'
  reason: string
}

/** 依頼書§40「判定結果を上書き」: 理由必須。 */
export function overridePaidLeaveScheduleEntry(
  entryId: string,
  input: OverridePaidLeaveScheduleEntryInput,
): Promise<PaidLeaveScheduleEntry> {
  return apiFetch(`/paid-leave/schedule-entries/${entryId}/override`, { method: 'POST', body: input })
}

/** 依頼書§39「一括付与」: 選択したエントリのうちEligibleのもののみ付与を発行する。 */
export function applyScheduledGrants(entryIds: string[]): Promise<ApplyScheduledGrantsResult> {
  return apiFetch('/paid-leave/schedule-entries/apply-grants', {
    method: 'POST',
    body: { entry_ids: entryIds },
  })
}
