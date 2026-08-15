import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Input } from '../../components/ui/input'
import { Separator } from '../../components/ui/separator'
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

  const { data: history, isLoading: isLoadingHistory } = useWorkflowRequestHistory(requestId)

  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')
  const [cancelError, setCancelError] = useState<Error | null>(null)

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="申請の取得に失敗しました。" />
  }
  if (!request) return null

  const { label, tone } = workflowRequestStatusLabel(request.status)
  const isApplicant = user?.id === request.applicant?.id
  const isApprover = user?.id === request.approver?.id
  const actionError = submitRequest.error ?? approveRequest.error ?? returnRequest.error

  /** 取消は元に戻せない操作(SKILL.md §2.12)のため、確認ダイアログ(ConfirmActionDialog)を
   *  経由させる。理由未入力の場合はダイアログを開いたまま留める。 */
  async function handleCancel() {
    if (!reason) {
      const emptyReasonError = new Error('取消理由を入力してください。')
      setCancelError(emptyReasonError)
      throw emptyReasonError
    }
    setCancelError(null)
    try {
      await cancelRequest.mutateAsync({ id: requestId, reason })
      setReason('')
    } catch (e) {
      setCancelError(e as Error)
      throw e
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link to="/requests" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
        ← 一覧へ戻る
      </Link>

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
          <AttachmentPanel ownerType="workflow_request" ownerId={requestId} />
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

        {/* Pattern exception: 承認/差戻し/取消/提出をここに直接配置する(SKILL.md §2.4は本来
            Primary CTAを右上、低頻度操作をOverflowへ集約するとしている)。
            Reason: これらはこの画面の主目的そのものである操作(§2.3が挙げる「一覧の主目的
            そのものである操作は行内に直接置いてよい」という例外と同種)であり、対象データ
            (入力内容・履歴)を確認した直後に選べる位置に置く方がFittsの法則的にも妥当なため、
            Detail Page下部への直置きを維持する。 */}
        <div className="flex flex-wrap items-center gap-3">
          {isApplicant && (request.status === 'draft' || request.status === 'returned') && (
            <Button isLoading={submitRequest.isPending} onClick={() => submitRequest.mutate({ id: requestId })}>
              提出する
            </Button>
          )}

          {isApplicant && ['draft', 'submitted', 'returned'].includes(request.status) && (
            <ConfirmActionDialog
              triggerLabel="取消"
              triggerVariant="danger"
              title={`「${request.title}」を取り消しますか?`}
              description="この操作は元に戻せません。申請は取消状態になります。"
              confirmLabel="取り消す"
              isPending={cancelRequest.isPending}
              error={cancelError}
              onConfirm={handleCancel}
              onOpenChange={(open) => {
                if (open) setCancelError(null)
              }}
            >
              <FormField label="取消理由" htmlFor="cancel-reason" required>
                <Input id="cancel-reason" placeholder="取消理由" value={reason} onChange={(e) => setReason(e.target.value)} />
              </FormField>
            </ConfirmActionDialog>
          )}

          {isApprover && request.status === 'submitted' && (
            <>
              <Button isLoading={approveRequest.isPending} onClick={() => approveRequest.mutate(requestId)}>
                承認する
              </Button>
              <div className="flex flex-col gap-1">
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
                {!comment && <p className="text-xs text-muted-foreground">差戻しコメントを入力してください。</p>}
              </div>
            </>
          )}
        </div>
      </div>
    </Card>
    </div>
  )
}
