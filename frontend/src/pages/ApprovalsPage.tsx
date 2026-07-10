import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useWorkflowRequestsToApprove } from '../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../utils/statusLabels'
import './ApprovalsPage.css'

/**
 * UC-W003/UC-W004: 承認者向けの承認待ち一覧(汎用申請)。
 */
export function ApprovalsPage() {
  const { data, isLoading, error } = useWorkflowRequestsToApprove()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ち一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  return (
    <Card title="承認待ちの申請">
      {requests.length === 0 ? (
        <p>承認待ちの申請はありません。</p>
      ) : (
        <ul className="approvals-list">
          {requests.map((request) => {
            const { label, tone } = workflowRequestStatusLabel(request.status)
            return (
              <li key={request.id}>
                <div>
                  <Link to={`/requests/${request.id}`}>{request.title}</Link>
                  <span className="approvals-list__applicant">{request.applicant?.name}</span>
                </div>
                <Badge tone={tone}>{label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
