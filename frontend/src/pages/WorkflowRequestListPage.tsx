import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useMyWorkflowRequests } from '../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../utils/statusLabels'
import './WorkflowRequestListPage.css'

/**
 * UC-W002手順6周辺: 自分の申請一覧。
 */
export function WorkflowRequestListPage() {
  const { data, isLoading, error } = useMyWorkflowRequests()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />

  const requests = data?.data ?? []

  return (
    <Card title="自分の申請">
      {requests.length === 0 ? (
        <p>申請はまだありません。</p>
      ) : (
        <ul className="workflow-request-list">
          {requests.map((request) => {
            const { label, tone } = workflowRequestStatusLabel(request.status)
            return (
              <li key={request.id}>
                <Link to={`/requests/${request.id}`}>{request.title}</Link>
                <span className="workflow-request-list__type">{request.request_type?.name}</span>
                <Badge tone={tone}>{label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
