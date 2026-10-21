import { apiFetch } from './client'
import type {
  PaidLeaveGrant,
  PaidLeaveGrantCycleType,
  PaidLeaveGrantPolicies,
  PaidLeaveGrantPolicyStep,
  PaidLeaveGrantRule,
  PaidLeaveGrantRuleStep,
  PaidLeaveGrantRuleTargetUser,
  PaidLeaveProportionalGrantPolicyStep,
  PaidLeaveRequest,
  PaidLeaveScheduleBulkGrantResponse,
  PaidLeaveScheduleEntry,
  PaidLeaveScheduleEntryDetail,
  PaidLeaveType,
  PaidLeaveUsage,
  StoredEvent,
} from './types'

export function fetchMyPaidLeaveGrants(): Promise<PaidLeaveGrant[]> {
  return apiFetch('/paid-leave/grants/mine')
}

export function fetchPaidLeaveGrantsForUser(userId: string): Promise<PaidLeaveGrant[]> {
  return apiFetch(`/paid-leave/grants/user/${userId}`)
}

export function fetchPaidLeaveGrantRules(): Promise<PaidLeaveGrantRule[]> {
  return apiFetch('/paid-leave/grant-rules')
}

export interface CreatePaidLeaveGrantRuleInput {
  name: string
  work_style_id?: string
  min_attendance_rate?: number
  first_grant_after_months?: number
  grant_cycle_months?: number
  grant_cycle_type: PaidLeaveGrantCycleType
  is_active?: boolean
  steps?: PaidLeaveGrantRuleStep[]
}

export function createPaidLeaveGrantRule(input: CreatePaidLeaveGrantRuleInput): Promise<PaidLeaveGrantRule> {
  return apiFetch('/paid-leave/grant-rules', { method: 'POST', body: input })
}

/** spec.md 論点15-1: ルール内容の編集(steps含む全置換)。 */
export function updatePaidLeaveGrantRule(
  id: number,
  input: CreatePaidLeaveGrantRuleInput,
): Promise<PaidLeaveGrantRule> {
  return apiFetch(`/paid-leave/grant-rules/${id}`, { method: 'PUT', body: input })
}

/** 無効化済み(is_active=false)のルールのみ削除できる(バックエンド側で強制)。 */
export function deletePaidLeaveGrantRule(id: number): Promise<void> {
  return apiFetch(`/paid-leave/grant-rules/${id}`, { method: 'DELETE' })
}

/** 法定通常付与表・比例付与表(最新version)。ルール編集フォームの参考値・読み取り専用マトリクス表示に使う。 */
export function fetchPaidLeaveGrantPolicies(): Promise<PaidLeaveGrantPolicies> {
  return apiFetch<{ data: PaidLeaveGrantPolicies }>('/paid-leave/grant-policies').then((res) => res.data)
}

/** 法定通常付与表の新バージョンを作成する(spec.md Feature 5)。既存versionは変更されない。 */
export function createPaidLeaveGrantPolicyVersion(
  rows: PaidLeaveGrantPolicyStep[],
): Promise<{ version: string; normal: PaidLeaveGrantPolicyStep[] }> {
  return apiFetch<{ data: { version: string; normal: PaidLeaveGrantPolicyStep[] } }>('/paid-leave/grant-policies', {
    method: 'POST',
    body: { rows },
  }).then((res) => res.data)
}

/** 法定比例付与表の新バージョンを作成する(spec.md Feature 5)。既存versionは変更されない。 */
export function createPaidLeaveProportionalGrantPolicyVersion(
  rows: PaidLeaveProportionalGrantPolicyStep[],
): Promise<{ version: string; proportional: PaidLeaveProportionalGrantPolicyStep[] }> {
  return apiFetch<{ data: { version: string; proportional: PaidLeaveProportionalGrantPolicyStep[] } }>(
    '/paid-leave/proportional-grant-policies',
    { method: 'POST', body: { rows } },
  ).then((res) => res.data)
}

/** ルールの対象条件(雇用形態/勤務体系)にマッチする社員の軽量一覧を取得する(対象社員セクション用)。 */
export function fetchPaidLeaveGrantRuleTargetUsers(ruleId: number): Promise<PaidLeaveGrantRuleTargetUser[]> {
  return apiFetch(`/paid-leave/grant-rules/${ruleId}/target-users`)
}

export interface GrantPaidLeaveInput {
  user_id: string
  granted_on: string
  expires_on: string
  granted_days: number
  grant_reason?: string
}

export function grantPaidLeave(input: GrantPaidLeaveInput): Promise<PaidLeaveGrant> {
  return apiFetch('/paid-leave/grants', { method: 'POST', body: input })
}

/** 使用日数が0の付与のみ取消可能(422で拒否される場合がある)。 */
export function revokePaidLeaveGrant(grantId: string, reason?: string): Promise<PaidLeaveGrant> {
  return apiFetch(`/paid-leave/grants/${grantId}/revoke`, { method: 'POST', body: { reason } })
}

export function fetchMyPaidLeaveRequests(): Promise<PaidLeaveRequest[]> {
  return apiFetch('/paid-leave/requests/mine')
}

export interface CreatePaidLeaveRequestInput {
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  /** system_settings.paid_leave_requires_approval が false の場合は省略可(承認不要)。 */
  approver_user_id?: string
  reason?: string
  /** 複数日をまとめて申請する場合、同一の申請操作内で生成した同じ値を全日分に渡す
   *  (承認者は対応するworkflow_requestのいずれか1件を承認するだけで、この値を共有する
   *  他の申請もまとめて承認できるようになる)。単日申請では省略する。 */
  request_group_id?: string
}

export function createPaidLeaveRequest(input: CreatePaidLeaveRequestInput): Promise<PaidLeaveRequest> {
  return apiFetch('/paid-leave/requests', { method: 'POST', body: input })
}

export function cancelPaidLeaveRequest(id: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** 管理者・人事担当者が対象社員の有給消化記録(usage)一覧を取得する(使用日の新しい順)。 */
export function fetchPaidLeaveUsagesForUser(userId: string): Promise<PaidLeaveUsage[]> {
  return apiFetch(`/paid-leave/usages/user/${userId}`)
}

/** 管理者による有給申請の取消(本人以外も対象)。承認済み(approved)の申請のみ取消可能で、
 *  紐づく消化記録(usage)をすべて一括で取り消す。 */
export function adminCancelPaidLeaveRequest(requestId: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${requestId}/admin-cancel`, { method: 'POST' })
}

/** UC-P007: 自分の有給履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMyPaidLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/paid-leave/history/mine')
}

/** UC-P007: 管理者・人事担当者が対象社員の有給履歴を取得する。 */
export function fetchPaidLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/paid-leave/history/user/${userId}`)
}

/** 付与予定の状態絞り込み。overdueは付与予定日を過ぎて未付与のもの(論点8)。 */
export type PaidLeaveScheduleFilter = 'all' | 'eligible' | 'not_eligible' | 'needs_review' | 'changed' | 'overdue'

export interface PaidLeaveScheduleEntriesParams {
  filter: PaidLeaveScheduleFilter
  user_name?: string
  scheduled_on_from?: string
  scheduled_on_to?: string
}

/**
 * 付与予定Scheduleエントリ一覧(spec.md論点4・8の絞り込み)。空文字・undefinedのパラメータは送らない。
 */
export function fetchPaidLeaveScheduleEntries(params: PaidLeaveScheduleEntriesParams): Promise<PaidLeaveScheduleEntry[]> {
  const query: Record<string, string> = { filter: params.filter }
  if (params.user_name) query.user_name = params.user_name
  if (params.scheduled_on_from) query.scheduled_on_from = params.scheduled_on_from
  if (params.scheduled_on_to) query.scheduled_on_to = params.scheduled_on_to
  return apiFetch<{ data: PaidLeaveScheduleEntry[] }>('/paid-leave/schedule-entries', { query }).then((res) => res.data)
}

/** 付与予定Scheduleエントリの詳細(Assessment履歴込み)。 */
export function fetchPaidLeaveScheduleEntry(scheduleEntryId: string): Promise<PaidLeaveScheduleEntryDetail> {
  return apiFetch<{ data: PaidLeaveScheduleEntryDetail }>(`/paid-leave/schedule-entries/${scheduleEntryId}`).then(
    (res) => res.data,
  )
}

/** 出勤率を同一条件で再判定する(依頼書§40「再判定」導線)。 */
export function reassessPaidLeaveScheduleEntry(scheduleEntryId: string): Promise<PaidLeaveScheduleEntry> {
  return apiFetch<{ data: PaidLeaveScheduleEntry }>(`/paid-leave/schedule-entries/${scheduleEntryId}/reassess`, {
    method: 'POST',
  }).then((res) => res.data)
}

export interface OverridePaidLeaveScheduleAssessmentInput {
  final_result: 'Eligible' | 'NotEligible'
  reason: string
}

/** 判定結果の上書き(理由必須、依頼書§40「判定結果を上書き」導線)。 */
export function overridePaidLeaveScheduleAssessment(
  scheduleEntryId: string,
  input: OverridePaidLeaveScheduleAssessmentInput,
): Promise<PaidLeaveScheduleEntry> {
  return apiFetch<{ data: PaidLeaveScheduleEntry }>(`/paid-leave/schedule-entries/${scheduleEntryId}/override`, {
    method: 'POST',
    body: input,
  }).then((res) => res.data)
}

/** 付与予定の区分・候補日数の個別修正の入力(spec.md論点3)。変更した項目だけを送る。 */
export interface EditPaidLeaveScheduleEntryInput {
  category?: 'normal' | 'proportional' | 'shift'
  candidate_grant_days?: number
  reason: string
}

/** 付与予定の区分・候補日数を理由付きで修正する(UC-P014後半、spec.md論点3)。付与予定日は変更しない。 */
export function editPaidLeaveScheduleEntry(
  scheduleEntryId: string,
  input: EditPaidLeaveScheduleEntryInput,
): Promise<PaidLeaveScheduleEntry> {
  return apiFetch<{ data: PaidLeaveScheduleEntry }>(`/paid-leave/schedule-entries/${scheduleEntryId}`, {
    method: 'PATCH',
    body: input,
  }).then((res) => res.data)
}

/** 選択したEligibleエントリを一括付与する(spec.md論点15-6、既存ManualGrantCardのResultSummaryパターンを踏襲)。 */
export function bulkGrantPaidLeaveScheduleEntries(scheduleEntryIds: string[]): Promise<PaidLeaveScheduleBulkGrantResponse> {
  return apiFetch('/paid-leave/schedule-entries/bulk-grant', {
    method: 'POST',
    body: { schedule_entry_ids: scheduleEntryIds },
  })
}
