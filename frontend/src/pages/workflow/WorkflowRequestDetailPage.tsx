import { useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { downloadAttachment } from '../../api/attachments'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { Separator } from '../../components/ui/separator'
import { useAttachments, useUploadAttachment } from '../../hooks/useAttachments'
import {
  useApproveWorkflowRequest,
  useCancelWorkflowRequest,
  useReturnWorkflowRequest,
  useSubmitWorkflowRequest,
  useWorkflowRequest,
  useWorkflowRequestHistory,
} from '../../hooks/useWorkflowRequests'
import { workflowRequestHistoryActionLabel, workflowRequestStatusLabel } from '../../utils/statusLabels'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes}B`
  return `${(bytes / 1024).toFixed(1)}KB`
}

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

/**
 * UC-W002〜UC-W005: 申請の詳細確認・提出・承認・差戻し・取消。
 */
export function WorkflowRequestDetailPage() {
  const { id } = useParams<{ id: string }>()
  const requestId = id ?? ''
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

      <div className="flex flex-col gap-6">
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <dt className="font-medium text-muted-foreground">申請種別</dt>
          <dd className="text-foreground">{request.request_type?.name}</dd>
          <dt className="font-medium text-muted-foreground">申請者</dt>
          <dd className="text-foreground">{request.applicant?.name}</dd>
          <dt className="font-medium text-muted-foreground">承認者</dt>
          <dd className="text-foreground">{request.approver?.name ?? '未指定'}</dd>
        </dl>

        <div className="flex flex-col gap-2">
          <SectionHeading>入力内容</SectionHeading>
          <dl className="flex flex-col">
            {Object.entries(request.form_data).map(([key, value]) => (
              <div key={key} className="flex gap-2 border-b border-border py-1.5 text-sm last:border-b-0">
                <dt className="min-w-[7.5rem] font-medium text-muted-foreground">{key}</dt>
                <dd className="text-foreground">{String(value)}</dd>
              </div>
            ))}
          </dl>
        </div>

        <div className="flex flex-col gap-2">
          <SectionHeading>添付ファイル</SectionHeading>
          {uploadAttachment.error && <ErrorMessage error={uploadAttachment.error} />}
          {isLoadingAttachments ? (
            <LoadingState />
          ) : (
            <ul className="flex flex-col" aria-label="添付ファイル">
              {(attachments ?? []).length === 0 && (
                <li className="py-1.5 text-sm text-muted-foreground">添付ファイルはありません。</li>
              )}
              {attachments?.map((attachment) => (
                <li
                  key={attachment.id}
                  className="flex items-center justify-between gap-3 border-b border-border py-1.5 text-sm last:border-b-0"
                >
                  <span className="text-foreground">
                    {attachment.file_name}({formatFileSize(attachment.file_size)})
                  </span>
                  <Button variant="secondary" onClick={() => void downloadAttachment(attachment.id, attachment.file_name)}>
                    ダウンロード
                  </Button>
                </li>
              ))}
            </ul>
          )}
          <div className="flex items-center gap-3">
            <input
              ref={fileInputRef}
              type="file"
              className="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1 file:text-sm file:font-medium file:text-foreground"
              onChange={(e) => {
                const file = e.target.files?.[0]
                if (!file) return
                uploadAttachment.mutate(
                  { ownerType: 'workflow_request', ownerId: requestId, file },
                  {
                    onSuccess: () => {
                      if (fileInputRef.current) fileInputRef.current.value = ''
                    },
                  },
                )
              }}
            />
            {uploadAttachment.isPending && <span className="text-sm text-muted-foreground">アップロード中...</span>}
          </div>
        </div>

        <div className="flex flex-col gap-2">
          <SectionHeading>履歴</SectionHeading>
          {isLoadingHistory ? (
            <LoadingState />
          ) : (
            <ul className="flex flex-col gap-1" aria-label="履歴">
              {history?.map((entry) => (
                <li key={entry.id} className="flex gap-3 text-sm">
                  <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(entry.occurred_at)}</span>
                  <span className="text-foreground">
                    {workflowRequestHistoryActionLabel(entry.action)}
                    {entry.comment ? `: ${entry.comment}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <Separator />

        <div className="flex flex-wrap items-center gap-3">
          {isApplicant && (request.status === 'draft' || request.status === 'returned') && (
            <Button isLoading={submitRequest.isPending} onClick={() => submitRequest.mutate({ id: requestId })}>
              提出する
            </Button>
          )}

          {isApplicant && ['draft', 'submitted', 'returned'].includes(request.status) && (
            <div className="flex items-center gap-2">
              <Input placeholder="取消理由" value={reason} onChange={(e) => setReason(e.target.value)} />
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
              <div className="flex items-center gap-2">
                <Input placeholder="差戻しコメント" value={comment} onChange={(e) => setComment(e.target.value)} />
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
      </div>
    </Card>
  )
}
