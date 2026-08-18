import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../EmptyState/EmptyState'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { LoadingState } from '../LoadingState/LoadingState'
import type { PaidLeaveRequestStatus, PaidLeaveType } from '../../api/types'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../../utils/statusLabels'

export interface LeaveUsageRow {
  id: string
  usedOn: string
  usedDays: number
  usedMinutes: number | null
  usageType: PaidLeaveType
  requestStatus: PaidLeaveRequestStatus | null
  /** この消化記録の元になった申請のID。同じ申請から複数の付与にまたがって消化された場合、
   *  複数のusage行が同じrequestIdを共有する。nullは申請を介さない消化(データ不整合等)。 */
  requestId: string | null
}

export interface LeaveUsageListProps {
  usages: LeaveUsageRow[] | undefined
  isLoading: boolean
  error?: unknown
  errorFallback?: string
  emptyTitle?: string
  emptyDescription?: string
  onCancelRequest: (requestId: string) => Promise<unknown>
  isCancelling?: boolean
  cancelError?: unknown
}

function formatDays(usedDays: number, usedMinutes: number | null): string {
  if (usedMinutes !== null) {
    return `${usedMinutes}分`
  }
  return `${usedDays}日`
}

/**
 * 有給・特別休暇・代休で共通利用する「消化記録(usage)」一覧。汎用的な時系列履歴
 * (`LeaveHistoryList`)とは異なり、実際の消化データそのものを行として見せ、
 * 承認済み申請の取消(管理者操作)を提供することが目的。
 *
 * 1件の承認済み申請が複数の付与(grant)にまたがって消化されている場合、同じ
 * `requestId`を持つ複数行として渡ってくる。取消はrequest単位でしか行えないため、
 * 同じrequestIdの行が複数あっても取消ボタンは最初の行にのみ表示し、確認ダイアログで
 * 「このN件がまとめて取り消される」ことを明示する。
 */
export function LeaveUsageList({
  usages,
  isLoading,
  error,
  errorFallback = '使用状況の取得に失敗しました。',
  emptyTitle = '使用状況はまだありません。',
  emptyDescription,
  onCancelRequest,
  isCancelling = false,
  cancelError,
}: LeaveUsageListProps) {
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback={errorFallback} />

  if (!usages || usages.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} />
  }

  const requestCounts = new Map<string, number>()
  for (const usage of usages) {
    if (!usage.requestId) continue
    requestCounts.set(usage.requestId, (requestCounts.get(usage.requestId) ?? 0) + 1)
  }

  const shownRequestIds = new Set<string>()

  return (
    <div className="overflow-x-auto">
      {cancelError !== undefined && cancelError !== null && <ErrorMessage error={cancelError} />}
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border text-left text-muted-foreground">
            <th className="py-2 pr-3 font-medium">使用日</th>
            <th className="py-2 pr-3 font-medium">日数/時間</th>
            <th className="py-2 pr-3 font-medium">取得単位</th>
            <th className="py-2 pr-3 font-medium">申請状況</th>
            <th className="py-2 pr-3 font-medium" />
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {usages.map((usage) => {
            const requestId = usage.requestId
            const groupSize = requestId ? (requestCounts.get(requestId) ?? 1) : 1
            const isFirstOfGroup = requestId !== null && !shownRequestIds.has(requestId)
            if (requestId && isFirstOfGroup) shownRequestIds.add(requestId)

            const statusMeta = usage.requestStatus ? paidLeaveRequestStatusLabel(usage.requestStatus) : null
            const canCancel = requestId !== null && isFirstOfGroup && usage.requestStatus === 'approved'
            const disabledReason =
              requestId === null
                ? undefined
                : usage.requestStatus !== 'approved'
                  ? '承認済みの申請のみ取り消せます。'
                  : undefined

            return (
              <tr key={usage.id}>
                <td className="py-2 pr-3 text-foreground">{usage.usedOn}</td>
                <td className="py-2 pr-3 text-foreground">{formatDays(usage.usedDays, usage.usedMinutes)}</td>
                <td className="py-2 pr-3 text-foreground">{paidLeaveTypeLabel(usage.usageType)}</td>
                <td className="py-2 pr-3">
                  {statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
                </td>
                <td className="py-2 pr-3">
                  {requestId === null ? null : isFirstOfGroup ? (
                    canCancel ? (
                      <ConfirmActionDialog
                        triggerLabel="取消"
                        title="この申請を取り消しますか?"
                        description={
                          groupSize > 1
                            ? `この申請に紐づく消化記録${groupSize}件のうち、${groupSize}件がまとめて取り消されます。この操作は元に戻せません。`
                            : `${usage.usedOn}の消化記録が取り消されます。この操作は元に戻せません。`
                        }
                        confirmLabel="取消する"
                        isPending={isCancelling}
                        error={cancelError}
                        onConfirm={() => onCancelRequest(requestId)}
                      />
                    ) : (
                      <Button variant="danger" size="sm" disabled title={disabledReason}>
                        取消
                      </Button>
                    )
                  ) : null}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
