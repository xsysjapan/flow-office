import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import {
  useApprovePaidLeaveRequest,
  usePaidLeaveRequestsToApprove,
  useReturnPaidLeaveRequest,
} from '../hooks/usePaidLeave'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../utils/statusLabels'

/**
 * UC-P004: 承認者向けの有給申請の承認・差戻し。
 */
export function PaidLeaveRequestsToApprovePage() {
  const { data, isLoading, error } = usePaidLeaveRequestsToApprove()
  const approveRequest = useApprovePaidLeaveRequest()
  const returnRequest = useReturnPaidLeaveRequest()

  const [comments, setComments] = useState<Record<number, string>>({})

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ちの有給申請の取得に失敗しました。" />

  const requests = data ?? []
  const actionError = approveRequest.error ?? returnRequest.error

  return (
    <Card title="承認待ちの有給申請">
      {actionError && <ErrorMessage error={actionError} />}

      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの有給申請はありません。</p>
      ) : (
        <ul className="divide-y divide-border">
          {requests.map((request) => {
            const { label, tone } = paidLeaveRequestStatusLabel(request.status)
            const comment = comments[request.id] ?? ''

            return (
              <li key={request.id} className="py-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-4 text-sm">
                    <span className="font-semibold text-foreground">{request.target_date}</span>
                    <span className="text-muted-foreground">{request.user?.name}</span>
                    <span className="text-muted-foreground">
                      {paidLeaveTypeLabel(request.leave_type)}({request.requested_days}日)
                    </span>
                  </div>
                  <Badge tone={tone}>{label}</Badge>
                </div>
                {request.reason && <p className="mt-1 text-sm text-muted-foreground">理由: {request.reason}</p>}

                <div className="mt-2 flex flex-wrap items-center gap-3">
                  <Button isLoading={approveRequest.isPending} onClick={() => approveRequest.mutate(request.id)}>
                    承認する
                  </Button>
                  <div className="flex items-center gap-2">
                    <Input
                      placeholder="差戻しコメント"
                      value={comment}
                      onChange={(e) => setComments((prev) => ({ ...prev, [request.id]: e.target.value }))}
                    />
                    <Button
                      variant="secondary"
                      isLoading={returnRequest.isPending}
                      disabled={!comment}
                      onClick={() => returnRequest.mutate({ id: request.id, comment })}
                    >
                      差戻す
                    </Button>
                  </div>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
