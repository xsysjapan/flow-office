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
import { WorkflowRequestSubjectDetail } from '../../components/WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail'
import { useAsset } from '../../hooks/useAsset'
import {
  useApproveWorkflowRequest,
  useCancelWorkflowRequest,
  useRejectWorkflowRequest,
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
 * 備品貸出申請(request_type=asset_loan)はsubject_type/subjectを持たない
 * (spec論点2: 専用Aggregate/ポリモーフィック連携を持たないただのデータ)ため、
 * form_data.asset_idから資産名・管理番号を解決して表示する(UC-C相当)。
 */
function AssetLoanRequestSubjectView({ assetId }: { assetId: string }) {
  const { data: asset, isLoading } = useAsset(assetId)

  if (isLoading) return <p className="text-sm text-muted-foreground">対象備品を確認しています…</p>
  if (!asset) return <p className="text-sm text-muted-foreground">対象備品(id: {assetId})が見つかりません。</p>

  return (
    <p className="text-sm text-foreground">
      対象備品:{' '}
      <Link to={`/assets/${asset.id}`} className="text-primary hover:underline">
        {asset.name}({asset.asset_no})
      </Link>
    </p>
  )
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
  const rejectRequest = useRejectWorkflowRequest()

  const { data: history, isLoading: isLoadingHistory } = useWorkflowRequestHistory(requestId)

  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')
  const [cancelError, setCancelError] = useState<Error | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [rejectError, setRejectError] = useState<Error | null>(null)

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
  /** 却下ボタンはspec論点2-2の通り、備品貸出申請(asset_loan)にのみ表示する
   *  (却下Command/Event自体は全申請種別で使える汎用実装だが、フロントの露出はここに限定)。 */
  const canReject = request.request_type?.code === 'asset_loan'
  /** 月次勤怠申請(attendance_month)の取消(取り下げ)は専用権限attendance.submission_revoke
   *  で個別に管理する(承認と別系列の権限。docs/07-usecases-attendance.md UC-A010参照)。
   *  他のsubject_type(経費精算・有給等)は申請者本人であれば従来通り取消可能。 */
  const canCancel =
    request.subject_type !== 'attendance_month' ||
    (user?.effective_permissions?.includes('attendance.submission_revoke') ?? false)

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

        {request.status === 'rejected' && request.rejection_reason && (
          <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-foreground">
            却下理由: {request.rejection_reason}
          </div>
        )}

        {request.subject_type && (
          <div className="flex flex-col gap-2">
            <SectionHeading>申請内容</SectionHeading>
            {/* 有給・代休・特別休暇・振替休日・経費精算・月次勤怠の申請内容(対象日・日数・
                金額・理由等)を、承認画面(ApprovalsPage/ApprovalDetailPanel)と同じ見た目で
                添付資料的に表示する。承認・却下等の操作は持たない読み取り専用ブロック。 */}
            <WorkflowRequestSubjectDetail request={request} />
          </div>
        )}

        {request.request_type?.code === 'asset_loan' && typeof request.form_data.asset_id === 'string' && (
          <div className="flex flex-col gap-2">
            <SectionHeading>申請内容</SectionHeading>
            <AssetLoanRequestSubjectView assetId={request.form_data.asset_id} />
          </div>
        )}

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

          {isApplicant && canCancel && ['draft', 'submitted', 'returned'].includes(request.status) && (
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
              {canReject && (
                <ConfirmActionDialog
                  triggerLabel="却下"
                  triggerVariant="danger"
                  title={`「${request.title}」を却下しますか?`}
                  description="この操作は元に戻せません。申請者は編集・再提出できなくなります。"
                  confirmLabel="却下する"
                  isPending={rejectRequest.isPending}
                  error={rejectError}
                  onConfirm={async () => {
                    if (!rejectReason) {
                      const emptyReasonError = new Error('却下理由を入力してください。')
                      setRejectError(emptyReasonError)
                      throw emptyReasonError
                    }
                    setRejectError(null)
                    try {
                      await rejectRequest.mutateAsync({ id: requestId, reason: rejectReason })
                      setRejectReason('')
                    } catch (e) {
                      setRejectError(e as Error)
                      throw e
                    }
                  }}
                  onOpenChange={(open) => {
                    if (open) setRejectError(null)
                  }}
                >
                  <FormField label="却下理由" htmlFor="reject-reason" required>
                    <Input
                      id="reject-reason"
                      placeholder="却下理由"
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                    />
                  </FormField>
                </ConfirmActionDialog>
              )}
            </>
          )}
        </div>
      </div>
    </Card>
    </div>
  )
}
