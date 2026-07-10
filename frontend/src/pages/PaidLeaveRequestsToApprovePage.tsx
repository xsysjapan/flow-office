import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import {
  useApprovePaidLeaveRequest,
  usePaidLeaveRequestsToApprove,
  useReturnPaidLeaveRequest,
} from '../hooks/usePaidLeave'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../utils/statusLabels'
import './PaidLeaveRequestsToApprovePage.css'

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
        <p>承認待ちの有給申請はありません。</p>
      ) : (
        <ul className="paid-leave-to-approve-list">
          {requests.map((request) => {
            const { label, tone } = paidLeaveRequestStatusLabel(request.status)
            const comment = comments[request.id] ?? ''

            return (
              <li key={request.id}>
                <div className="paid-leave-to-approve-list__row">
                  <div>
                    <span className="paid-leave-to-approve-list__date">{request.target_date}</span>
                    <span className="paid-leave-to-approve-list__user">{request.user?.name}</span>
                    <span className="paid-leave-to-approve-list__type">
                      {paidLeaveTypeLabel(request.leave_type)}({request.requested_days}日)
                    </span>
                  </div>
                  <Badge tone={tone}>{label}</Badge>
                </div>
                {request.reason && <p className="paid-leave-to-approve-list__reason">理由: {request.reason}</p>}

                <div className="paid-leave-to-approve-list__actions">
                  <Button isLoading={approveRequest.isPending} onClick={() => approveRequest.mutate(request.id)}>
                    承認する
                  </Button>
                  <div className="paid-leave-to-approve-list__with-comment">
                    <input
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
