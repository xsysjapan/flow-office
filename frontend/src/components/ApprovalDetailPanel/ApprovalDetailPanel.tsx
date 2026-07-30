import { useState } from 'react'
import type {
  WorkflowRequest,
  WorkflowRequestAttendanceMonthSubject,
  WorkflowRequestExpenseClaimSubject,
} from '../../api/types'
import { isoToTimeLiteral } from '../../utils/offsetDateTime'
import {
  attendanceDayStatusLabel,
  attendanceMonthStatusLabel,
  expenseClaimStatusLabel,
  paymentBearerLabel,
  workflowRequestStatusLabel,
} from '../../utils/statusLabels'
import { AttachmentPanel } from '../AttachmentPanel/AttachmentPanel'
import { Badge } from '../Badge/Badge'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { Input } from '../ui/input'
import { Separator } from '../ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'

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

function AttendanceMonthSubjectView({ subject }: { subject: WorkflowRequestAttendanceMonthSubject }) {
  const { label, tone } = attendanceMonthStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
        <span className="text-sm font-semibold text-foreground">{subject.year_month}</span>
      </div>

      {subject.return_comment && (
        <div className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
          差戻し理由: {subject.return_comment}
        </div>
      )}

      <div className="flex flex-col gap-2">
        <SectionHeading>{`日別の内訳(${subject.days.length}日)`}</SectionHeading>
        {subject.days.length === 0 ? (
          <p className="text-sm text-muted-foreground">実績がありません。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>勤務日</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>出退勤</TableHead>
                <TableHead>休憩</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {subject.days.map((day) => {
                const dayMeta = attendanceDayStatusLabel(day.status)
                return (
                  <TableRow key={day.id}>
                    <TableCell className="text-muted-foreground">{day.work_date}</TableCell>
                    <TableCell>
                      <Badge tone={dayMeta.tone}>{dayMeta.label}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {isoToTimeLiteral(day.actual_start_at) || '--:--'} 〜{' '}
                      {isoToTimeLiteral(day.actual_end_at) || '--:--'}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {day.breaks.length === 0
                        ? '-'
                        : day.breaks
                            .map(
                              (b) =>
                                `${isoToTimeLiteral(b.break_start_at) || '--:--'}〜${isoToTimeLiteral(b.break_end_at) || '--:--'}`,
                            )
                            .join(', ')}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </div>
    </div>
  )
}

function ExpenseClaimSubjectView({ subject }: { subject: WorkflowRequestExpenseClaimSubject }) {
  const { label, tone } = expenseClaimStatusLabel(subject.status)
  const periodLabel =
    subject.period_from && subject.period_to ? `${subject.period_from} 〜 ${subject.period_to}` : '対象期間未確定'

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
        <span className="text-sm text-muted-foreground">{periodLabel}</span>
      </div>

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">合計金額</dt>
        <dd className="text-foreground">{subject.total_amount.toLocaleString()}円</dd>
      </dl>

      <div className="flex flex-col gap-2">
        <SectionHeading>{`明細(${subject.items.length}件)`}</SectionHeading>
        {subject.items.length === 0 ? (
          <p className="text-sm text-muted-foreground">明細はありません。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>日付</TableHead>
                <TableHead>経費区分</TableHead>
                <TableHead>内容</TableHead>
                <TableHead>金額</TableHead>
                <TableHead>負担</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {subject.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell className="text-muted-foreground">{item.usage_date}</TableCell>
                  <TableCell className="text-muted-foreground">{item.category_name}</TableCell>
                  <TableCell className="text-muted-foreground">{item.description}</TableCell>
                  <TableCell className="text-muted-foreground">{item.amount.toLocaleString()}円</TableCell>
                  <TableCell className="text-muted-foreground">
                    {item.payment_bearer ? paymentBearerLabel(item.payment_bearer) : '-'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
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
 * 統合承認画面の詳細パネル。`request.subject_type`(null/attendance_month/expense_claim)に
 * 応じて表示内容(汎用申請のform_data・月次勤怠の日別内訳・経費精算の明細)を切り替える。
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

      {request.subject_type === 'attendance_month' && request.subject?.type === 'attendance_month' && (
        <AttendanceMonthSubjectView subject={request.subject} />
      )}

      {request.subject_type === 'expense_claim' && request.subject?.type === 'expense_claim' && (
        <ExpenseClaimSubjectView subject={request.subject} />
      )}

      {!request.subject_type && <WorkflowRequestSubjectView request={request} />}

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
