import { useState } from 'react'
import type { WorkflowRequest } from '../../api/types'
import { workflowRequestStatusLabel } from '../../utils/statusLabels'
import { WorkflowRequestSubjectDetail } from '../WorkflowRequestSubjectDetail/WorkflowRequestSubjectDetail'
import { AttachmentPanel } from '../AttachmentPanel/AttachmentPanel'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { Input } from '../ui/input'
import { Separator } from '../ui/separator'

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

/** その申請(subject_type)が現時点で承認・差戻しできる状態かどうか。 */
function isActionable(request: WorkflowRequest): boolean {
  if (request.subject_type === 'attendance_month') {
    return request.subject?.type === 'attendance_month' && request.subject.status === 'submitted'
  }
  if (request.subject_type === 'expense_claim') {
    return request.subject?.type === 'expense_claim' && request.subject.status === 'in_review'
  }
  if (request.subject_type === 'paid_leave_request') {
    return request.subject?.type === 'paid_leave_request' && request.subject.status === 'submitted'
  }
  if (request.subject_type === 'special_leave_request') {
    return request.subject?.type === 'special_leave_request' && request.subject.status === 'submitted'
  }
  if (request.subject_type === 'shift_swap_request') {
    return request.subject?.type === 'shift_swap_request' && request.subject.status === 'submitted'
  }
  if (request.subject_type === 'compensatory_leave_request') {
    return request.subject?.type === 'compensatory_leave_request' && request.subject.status === 'submitted'
  }
  return request.status === 'submitted'
}

function WorkflowRequestSubjectView({ request }: { request: WorkflowRequest }) {
  const { label, tone } = workflowRequestStatusLabel(request.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
        {request.request_type?.name && <span className="text-sm text-muted-foreground">{request.request_type.name}</span>}
      </div>
      <div className="flex flex-col gap-2">
        <SectionHeading>入力内容</SectionHeading>
        {Object.keys(request.form_data).length === 0 ? (
          <p className="text-sm text-muted-foreground">入力内容はありません。</p>
        ) : (
          <dl className="flex flex-col">
            {Object.entries(request.form_data).map(([key, value]) => (
              <div key={key} className="flex gap-2 border-b border-border py-1.5 text-sm last:border-b-0">
                <dt className="min-w-[7.5rem] font-medium text-muted-foreground">{key}</dt>
                <dd className="text-foreground">{String(value)}</dd>
              </div>
            ))}
          </dl>
        )}
      </div>
      <div className="flex flex-col gap-2">
        <SectionHeading>添付ファイル</SectionHeading>
        <AttachmentPanel ownerType="workflow_request" ownerId={request.id} readOnly />
      </div>
    </div>
  )
}

export interface ApprovalDetailPanelProps {
  request: WorkflowRequest
  approveIsPending?: boolean
  returnIsPending?: boolean
  actionError?: Error | null
  onApprove: () => void
  onReturn: (comment: string) => void
}

/**
 * 統合承認画面の詳細パネル。`request.subject_type`(null/attendance_month/expense_claim/
 * paid_leave_request/special_leave_request)に応じて表示内容(汎用申請のform_data・月次勤怠の
 * 日別内訳・経費精算の明細・有給/特別休暇申請の内容)を切り替える。
 * 承認・差戻しの実際のAPI呼び出し(対象ドメインへの振り分け)は呼び出し側
 * (hooks/useApprovals)に委ね、このコンポーネントは表示とコールバック呼び出しのみを行う。
 */
export function ApprovalDetailPanel({
  request,
  approveIsPending = false,
  returnIsPending = false,
  actionError,
  onApprove,
  onReturn,
}: ApprovalDetailPanelProps) {
  const [comment, setComment] = useState('')
  const actionable = isActionable(request)

  return (
    <div className="flex flex-col gap-6">
      {actionError && <ErrorMessage error={actionError} />}

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">申請者</dt>
        <dd className="text-foreground">{request.applicant?.name}</dd>
        <dt className="font-medium text-muted-foreground">承認者</dt>
        <dd className="text-foreground">{request.approver?.name ?? '未指定'}</dd>
      </dl>

      {request.subject_type ? (
        <WorkflowRequestSubjectDetail request={request} />
      ) : (
        <WorkflowRequestSubjectView request={request} />
      )}

      <Separator />

      {actionable ? (
        <div className="flex flex-wrap items-center gap-3">
          <Button isLoading={approveIsPending} onClick={onApprove}>
            承認する
          </Button>
          <div className="flex items-center gap-2">
            <Input placeholder="差戻しコメント" value={comment} onChange={(e) => setComment(e.target.value)} />
            <Button variant="secondary" isLoading={returnIsPending} disabled={!comment} onClick={() => onReturn(comment)}>
              差戻す
            </Button>
          </div>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">この申請は現在操作できません。</p>
      )}
    </div>
  )
}
