import { useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { downloadAttachment } from '../api/attachments'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useAttachments, useUploadAttachment } from '../hooks/useAttachments'
import {
  useApproveWorkflowRequest,
  useCancelWorkflowRequest,
  useReturnWorkflowRequest,
  useSubmitWorkflowRequest,
  useWorkflowRequest,
  useWorkflowRequestHistory,
} from '../hooks/useWorkflowRequests'
import { workflowRequestEventTypeLabel, workflowRequestStatusLabel } from '../utils/statusLabels'
import './WorkflowRequestDetailPage.css'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes}B`
  return `${(bytes / 1024).toFixed(1)}KB`
}

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

  const { data: attachments, isLoading: isLoadingAttachments } = useAttachments('workflow_request', requestId)
  const uploadAttachment = useUploadAttachment()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const { data: history, isLoading: isLoadingHistory } = useWorkflowRequestHistory(requestId)

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

      <h3>添付ファイル</h3>
      {uploadAttachment.error && <ErrorMessage error={uploadAttachment.error} />}
      {isLoadingAttachments ? (
        <LoadingState />
      ) : (
        <ul className="workflow-request-detail__attachments" aria-label="添付ファイル">
          {(attachments ?? []).length === 0 && <li>添付ファイルはありません。</li>}
          {attachments?.map((attachment) => (
            <li key={attachment.id}>
              <span>
                {attachment.file_name}({formatFileSize(attachment.file_size)})
              </span>
              <Button variant="secondary" onClick={() => void downloadAttachment(attachment.id, attachment.file_name)}>
                ダウンロード
              </Button>
            </li>
          ))}
        </ul>
      )}
      <div className="workflow-request-detail__upload">
        <input
          ref={fileInputRef}
          type="file"
          onChange={(e) => {
            const file = e.target.files?.[0]
            if (!file) return
            uploadAttachment.mutate(
              { ownerType: 'workflow_request', ownerId: requestId, file },
              { onSuccess: () => {
                if (fileInputRef.current) fileInputRef.current.value = ''
              } },
            )
          }}
        />
        {uploadAttachment.isPending && <span>アップロード中...</span>}
      </div>

      <h3>履歴</h3>
      {isLoadingHistory ? (
        <LoadingState />
      ) : (
        <ul className="workflow-request-detail__history" aria-label="履歴">
          {history?.map((event) => (
            <li key={event.id}>
              <span className="workflow-request-detail__history-time">{formatDateTime(event.occurred_at)}</span>
              <span>{workflowRequestEventTypeLabel(event.event_type)}</span>
            </li>
          ))}
        </ul>
      )}

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
