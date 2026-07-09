import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import {
  useApproveWorkflowRequest,
  useCancelWorkflowRequest,
  useReturnWorkflowRequest,
  useSubmitWorkflowRequest,
  useWorkflowRequest,
} from '../hooks/useWorkflowRequests'
import { workflowRequestStatusLabel } from '../utils/statusLabels'
import './WorkflowRequestDetailPage.css'

/**
 * UC-W002〜UC-W005: 申請の詳細確認・提出・承認・差戻し・取消。
 */
export function WorkflowRequestDetailPage() {
  const { id } = useParams<{ id: string }>()
  const requestId = Number(id)
  const { user } = useAuth()
  const { data: request, isLoading, error } = useWorkflowRequest(requestId)

  const submitRequest = useSubmitWorkflowRequest()
  const approveRequest = useApproveWorkflowRequest()
  const returnRequest = useReturnWorkflowRequest()
  const cancelRequest = useCancelWorkflowRequest()

  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="申請の取得に失敗しました。" />
  if (!request) return null

  const { label, tone } = workflowRequestStatusLabel(request.status)
  const isApplicant = user?.id === request.applicant?.id
  const isApprover = user?.id === request.approver?.id
  const actionError =
    submitRequest.error ?? approveRequest.error ?? returnRequest.error ?? cancelRequest.error

  return (
    <Card title={request.title} actions={<Badge tone={tone}>{label}</Badge>}>
      {actionError && <ErrorMessage error={actionError} />}

      <dl className="workflow-request-detail__meta">
        <dt>申請種別</dt>
        <dd>{request.request_type?.name}</dd>
        <dt>申請者</dt>
        <dd>{request.applicant?.name}</dd>
        <dt>承認者</dt>
        <dd>{request.approver?.name ?? '未指定'}</dd>
      </dl>

      <h3>入力内容</h3>
      <dl className="workflow-request-detail__form-data">
        {Object.entries(request.form_data).map(([key, value]) => (
          <div key={key}>
            <dt>{key}</dt>
            <dd>{String(value)}</dd>
          </div>
        ))}
      </dl>

      <div className="workflow-request-detail__actions">
        {isApplicant && (request.status === 'draft' || request.status === 'returned') && (
          <Button isLoading={submitRequest.isPending} onClick={() => submitRequest.mutate({ id: requestId })}>
            提出する
          </Button>
        )}

        {isApplicant && ['draft', 'submitted', 'returned'].includes(request.status) && (
          <div className="workflow-request-detail__with-comment">
            <input
              placeholder="取消理由"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <Button
              variant="danger"
              isLoading={cancelRequest.isPending}
              disabled={!reason}
              onClick={() => cancelRequest.mutate({ id: requestId, reason })}
            >
              取り消す
            </Button>
          </div>
        )}

        {isApprover && request.status === 'submitted' && (
          <>
            <Button isLoading={approveRequest.isPending} onClick={() => approveRequest.mutate(requestId)}>
              承認する
            </Button>
            <div className="workflow-request-detail__with-comment">
              <input
                placeholder="差戻しコメント"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
              />
              <Button
                variant="secondary"
                isLoading={returnRequest.isPending}
                disabled={!comment}
                onClick={() => returnRequest.mutate({ id: requestId, comment })}
              >
                差戻す
              </Button>
            </div>
          </>
        )}
      </div>
    </Card>
  )
}
