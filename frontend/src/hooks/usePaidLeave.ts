import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import {
  adminCancelPaidLeaveRequest,
  bulkGrantPaidLeaveScheduleEntries,
  cancelPaidLeaveRequest,
  createPaidLeaveGrantPolicyVersion,
  createPaidLeaveGrantRule,
  createPaidLeaveProportionalGrantPolicyVersion,
  createPaidLeaveRequest,
  deletePaidLeaveGrantRule,
  editPaidLeaveScheduleEntry,
  fetchMyPaidLeaveGrants,
  fetchMyPaidLeaveHistory,
  fetchMyPaidLeaveRequests,
  fetchPaidLeaveGrantPolicies,
  fetchPaidLeaveGrantRules,
  fetchPaidLeaveGrantRuleTargetUsers,
  fetchPaidLeaveGrantsForUser,
  fetchPaidLeaveHistoryForUser,
  fetchPaidLeaveScheduleAssessmentRunStatus,
  fetchPaidLeaveScheduleEntries,
  fetchPaidLeaveScheduleEntry,
  fetchPaidLeaveUsagesForUser,
  grantPaidLeave,
  overridePaidLeaveScheduleAssessment,
  reassessPaidLeaveScheduleEntry,
  changePaidLeaveGrantedDays,
  revokePaidLeaveGrant,
  startPaidLeaveScheduleAssessmentRun,
  updatePaidLeaveGrantRule,
  type CreatePaidLeaveGrantRuleInput,
  type CreatePaidLeaveRequestInput,
  type EditPaidLeaveScheduleEntryInput,
  type GrantPaidLeaveInput,
  type OverridePaidLeaveScheduleAssessmentInput,
  type PaidLeaveScheduleEntriesParams,
  type FetchMyPaidLeaveRequestsParams,
} from '../api/paidLeave'
import type { PaidLeaveGrantPolicyStep, PaidLeaveProportionalGrantPolicyStep, PaidLeaveScheduleAssessmentRun } from '../api/types'

/** 実行中の自動判定の状態を取り直す間隔(ms)。実行中のときだけポーリングする。 */
export const SCHEDULE_ASSESSMENT_RUN_POLL_INTERVAL_MS = 5000
const SCHEDULE_ASSESSMENT_RUN_STATUS_KEY = ['paid-leave', 'schedule-assessment-runs', 'status']

function isActiveAssessmentRun(run: PaidLeaveScheduleAssessmentRun | null | undefined): boolean {
  return run?.status === 'queued' || run?.status === 'running'
}

const RULES_KEY = ['paid-leave', 'grant-rules']
const GRANT_POLICIES_KEY = ['paid-leave', 'grant-policies']
const MY_GRANTS_KEY = ['paid-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['paid-leave', 'requests', 'mine']

function scheduleEntriesKey(params: PaidLeaveScheduleEntriesParams) {
  return ['paid-leave', 'schedule-entries', 'list', params]
}

function scheduleEntryKey(scheduleEntryId: string) {
  return ['paid-leave', 'schedule-entries', 'detail', scheduleEntryId]
}

export function useMyPaidLeaveGrants() {
  return useQuery({ queryKey: MY_GRANTS_KEY, queryFn: fetchMyPaidLeaveGrants })
}

export function usePaidLeaveGrantsForUser(userId: string) {
  return useQuery({
    queryKey: ['paid-leave', 'grants', 'user', userId],
    queryFn: () => fetchPaidLeaveGrantsForUser(userId),
    enabled: Boolean(userId),
  })
}

export function usePaidLeaveGrantRules() {
  return useQuery({ queryKey: RULES_KEY, queryFn: fetchPaidLeaveGrantRules })
}

/** 付与ルールの対象社員一覧(対象社員セクション用)。展開時にのみ取得すればよいので `enabled` で制御する。 */
export function usePaidLeaveGrantRuleTargetUsers(ruleId: number, enabled = true) {
  return useQuery({
    queryKey: ['paid-leave', 'grant-rules', ruleId, 'target-users'],
    queryFn: () => fetchPaidLeaveGrantRuleTargetUsers(ruleId),
    enabled,
  })
}

export function useCreatePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreatePaidLeaveGrantRuleInput) => createPaidLeaveGrantRule(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}

/** spec.md 論点15-1: ルール内容の編集。ルール一覧・(存在すれば)対象社員一覧を無効化する。 */
export function useUpdatePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CreatePaidLeaveGrantRuleInput }) => updatePaidLeaveGrantRule(id, input),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grant-rules', id, 'target-users'] })
    },
  })
}

/** 無効化済みルールの削除。 */
export function useDeletePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deletePaidLeaveGrantRule(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}

/** 法定通常付与表・比例付与表(最新version)。滅多に変わらない参照データのためstaleTimeを長めにする。 */
export function usePaidLeaveGrantPolicies() {
  return useQuery({
    queryKey: GRANT_POLICIES_KEY,
    queryFn: fetchPaidLeaveGrantPolicies,
    staleTime: 5 * 60 * 1000,
  })
}

/** 法定通常付与表の新バージョン作成(spec.md Feature 5)。成功時は読み取り専用マトリクスを再取得させる。 */
export function useCreatePaidLeaveGrantPolicyVersion() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (rows: PaidLeaveGrantPolicyStep[]) => createPaidLeaveGrantPolicyVersion(rows),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: GRANT_POLICIES_KEY })
    },
  })
}

/** 法定比例付与表の新バージョン作成(spec.md Feature 5)。成功時は読み取り専用マトリクスを再取得させる。 */
export function useCreatePaidLeaveProportionalGrantPolicyVersion() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (rows: PaidLeaveProportionalGrantPolicyStep[]) => createPaidLeaveProportionalGrantPolicyVersion(rows),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: GRANT_POLICIES_KEY })
    },
  })
}

export function useGrantPaidLeave() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GrantPaidLeaveInput) => grantPaidLeave(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants', 'user', input.user_id] })
    },
  })
}

export function useChangePaidLeaveGrantedDays() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, grantedDays, reason }: { grantId: string; grantedDays: number; reason?: string }) =>
      changePaidLeaveGrantedDays(grantId, grantedDays, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants', 'user', data.user_id] })
      void queryClient.invalidateQueries({ queryKey: paidLeaveUsagesForUserKey(data.user_id) })
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'history', 'user', data.user_id] })
    },
  })
}

export function useRevokePaidLeaveGrant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, reason }: { grantId: string; reason?: string }) => revokePaidLeaveGrant(grantId, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants', 'user', data.user_id] })
      void queryClient.invalidateQueries({ queryKey: paidLeaveUsagesForUserKey(data.user_id) })
    },
  })
}

export function useMyPaidLeaveRequests(params: FetchMyPaidLeaveRequestsParams = {}) {
  return useQuery({ queryKey: [...MY_REQUESTS_KEY, params], queryFn: () => fetchMyPaidLeaveRequests(params) })
}

export function useCreatePaidLeaveRequest() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreatePaidLeaveRequestInput) => createPaidLeaveRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    },
  })
}

function useInvalidatePaidLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useCancelPaidLeaveRequest() {
  const invalidate = useInvalidatePaidLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => cancelPaidLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useMyPaidLeaveHistory() {
  return useQuery({ queryKey: ['paid-leave', 'history', 'mine'], queryFn: fetchMyPaidLeaveHistory })
}

export function usePaidLeaveHistoryForUser(userId: string) {
  return useQuery({
    queryKey: ['paid-leave', 'history', 'user', userId],
    queryFn: () => fetchPaidLeaveHistoryForUser(userId),
    enabled: Boolean(userId),
  })
}

function paidLeaveUsagesForUserKey(userId: string) {
  return ['paid-leave', 'usages', 'user', userId]
}

export function usePaidLeaveUsagesForUser(userId: string) {
  return useQuery({
    queryKey: paidLeaveUsagesForUserKey(userId),
    queryFn: () => fetchPaidLeaveUsagesForUser(userId),
    enabled: Boolean(userId),
  })
}

/** 管理者による有給申請の取消。取消対象社員のusages一覧を再取得する。 */
export function useAdminCancelPaidLeaveRequest(userId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (requestId: string) => adminCancelPaidLeaveRequest(requestId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: paidLeaveUsagesForUserKey(userId) })
    },
  })
}

/** 付与予定Scheduleエントリ一覧(spec.md論点4・8。絞り込みはページ側でURLへ同期する)。再取得中は前の結果を保持する。 */
export function usePaidLeaveScheduleEntries(params: PaidLeaveScheduleEntriesParams) {
  return useQuery({
    queryKey: scheduleEntriesKey(params),
    queryFn: () => fetchPaidLeaveScheduleEntries(params),
    placeholderData: keepPreviousData,
  })
}

/** 付与予定Scheduleエントリの詳細(行詳細Sheet用)。 */
export function usePaidLeaveScheduleEntry(scheduleEntryId: string | null) {
  return useQuery({
    queryKey: scheduleEntryKey(scheduleEntryId ?? ''),
    queryFn: () => fetchPaidLeaveScheduleEntry(scheduleEntryId as string),
    enabled: scheduleEntryId !== null,
  })
}

/** 付与予定の一覧+詳細のキャッシュをまとめて無効化する(再判定・上書きの共通後処理)。 */
function useInvalidatePaidLeaveScheduleEntries() {
  const queryClient = useQueryClient()

  return (scheduleEntryId?: string) => {
    void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'schedule-entries'] })
    if (scheduleEntryId) {
      void queryClient.invalidateQueries({ queryKey: scheduleEntryKey(scheduleEntryId) })
    }
  }
}

/** 出勤率の再判定(依頼書§40「再判定」導線)。 */
export function useReassessPaidLeaveScheduleEntry() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (scheduleEntryId: string) => reassessPaidLeaveScheduleEntry(scheduleEntryId),
    onSuccess: (data) => invalidate(data.id),
  })
}

/** 判定結果の上書き(理由必須、依頼書§40「判定結果を上書き」導線)。 */
export function useOverridePaidLeaveScheduleAssessment() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: ({
      scheduleEntryId,
      input,
    }: {
      scheduleEntryId: string
      input: OverridePaidLeaveScheduleAssessmentInput
    }) => overridePaidLeaveScheduleAssessment(scheduleEntryId, input),
    onSuccess: (data) => invalidate(data.id),
  })
}

/**
 * 付与予定の区分・候補日数の個別修正(spec.md論点3、UC-P014後半)。修正は一覧の「変更あり」と詳細に
 * 反映されるため、一覧と詳細を無効化する。無効化を待ってから完了を返す(完了時点で画面が新しい内容になるよう、
 * 呼び出し側の成功後処理で未保存フラグを正しく解除するため)。
 */
export function useEditPaidLeaveScheduleEntry() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ scheduleEntryId, input }: { scheduleEntryId: string; input: EditPaidLeaveScheduleEntryInput }) =>
      editPaidLeaveScheduleEntry(scheduleEntryId, input),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['paid-leave', 'schedule-entries'] }),
        queryClient.invalidateQueries({ queryKey: scheduleEntryKey(data.id) }),
      ])
    },
  })
}

/**
 * 選択したEligibleエントリの一括付与(spec.md論点5)。付与が起きるため、付与・履歴・使用状況の
 * 画面キャッシュも全社員分を無効化する(プレフィックス一致)。
 */
export function useBulkGrantPaidLeaveScheduleEntries() {
  const queryClient = useQueryClient()
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (scheduleEntryIds: string[]) => bulkGrantPaidLeaveScheduleEntries(scheduleEntryIds),
    onSuccess: () => {
      invalidate()
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants'] })
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'history'] })
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'usages'] })
    },
  })
}

/**
 * 自動判定の実行状況(付与予定画面の「自動判定」カード)。実行中(queued/running)の間だけ5秒ごとに取り直す。
 * 実行中から終わったら、付与予定の一覧・詳細と超過件数(一覧のキャッシュ)を取り直す。
 */
export function usePaidLeaveScheduleAssessmentRunStatus() {
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: SCHEDULE_ASSESSMENT_RUN_STATUS_KEY,
    queryFn: fetchPaidLeaveScheduleAssessmentRunStatus,
    refetchInterval: (current) =>
      isActiveAssessmentRun(current.state.data?.latest_run) ? SCHEDULE_ASSESSMENT_RUN_POLL_INTERVAL_MS : false,
  })

  const isActive = isActiveAssessmentRun(query.data?.latest_run)
  const wasActive = useRef(false)
  useEffect(() => {
    if (wasActive.current && !isActive) {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'schedule-entries'] })
    }
    wasActive.current = isActive
  }, [isActive, queryClient])

  return query
}

/** 「今すぐ判定する」。受付後(成功・失敗のどちらでも)に実行状況を取り直す。実行中のときの409もここで状態を取り直す。 */
export function useStartPaidLeaveScheduleAssessmentRun() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => startPaidLeaveScheduleAssessmentRun(),
    onSettled: () => queryClient.invalidateQueries({ queryKey: SCHEDULE_ASSESSMENT_RUN_STATUS_KEY }),
  })
}
