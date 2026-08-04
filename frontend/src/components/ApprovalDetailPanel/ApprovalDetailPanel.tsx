import { useState } from 'react'
import type {
  WorkflowRequest,
  WorkflowRequestAttendanceMonthSubject,
  WorkflowRequestExpenseClaimSubject,
  WorkflowRequestPaidLeaveRequestSubject,
  WorkflowRequestSpecialLeaveRequestSubject,
} from '../../api/types'
import { DailyReferenceView, MonthlyReferenceView, WeeklyReferenceView } from '../../pages/attendance/AttendanceReferencePage'
import { datesInMonth, formatDate, mondayOf } from '../../utils/weekDates'
import {
  attendanceMonthStatusLabel,
  expenseClaimStatusLabel,
  paidLeaveRequestStatusLabel,
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
  if (request.subject_type === 'paid_leave_request') {
    return request.subject?.type === 'paid_leave_request' && request.subject.status === 'submitted'
  }
  if (request.subject_type === 'special_leave_request') {
    return request.subject?.type === 'special_leave_request' && request.subject.status === 'submitted'
  }
  return request.status === 'submitted'
}

/** 有給・特別休暇申請の日数/時間表示。時間休(hourly)のみ時間で表示し、それ以外は日数で表示する
 *  (MyPaidLeavePageと同じ考え方)。 */
function leaveRequestedAmountLabel(subject: { leave_type: string; hours: number | null; requested_days: number }): string {
  if (subject.leave_type === 'hourly' && subject.hours !== null) {
    return `${subject.hours}時間`
  }
  return `${subject.requested_days}日`
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

type AttendanceSubjectViewMode = 'month' | 'week' | 'day'

const ATTENDANCE_SUBJECT_VIEW_MODES: Array<{ key: AttendanceSubjectViewMode; label: string }> = [
  { key: 'month', label: '月次' },
  { key: 'week', label: '週次' },
  { key: 'day', label: '日次' },
]

/**
 * 承認者が月次勤怠申請の対象社員の実際の勤務表を月次・週次・日次で確認するための
 * タブ切り替え(AttendanceReferencePageのVIEW_MODESと同じ見た目に揃える)。
 * 月次一覧の行を選ぶと日次タブへ切り替わる(MonthAttendanceReview/旧MonthsToApprovePageと
 * 同じドリルダウン導線)。レビュー対象はこの申請の年月に限定し、他の月には遷移できない。
 */
function AttendanceMonthSubjectView({ subject }: { subject: WorkflowRequestAttendanceMonthSubject }) {
  const { label, tone } = attendanceMonthStatusLabel(subject.status)
  const [viewMode, setViewMode] = useState<AttendanceSubjectViewMode>('month')
  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const dates = datesInMonth(subject.year_month)
  const dateRange = { min: dates[0], max: dates[dates.length - 1] }
  const weekRange = {
    min: formatDate(mondayOf(new Date(`${dates[0]}T00:00:00`))),
    max: formatDate(mondayOf(new Date(`${dates[dates.length - 1]}T00:00:00`))),
  }

  function handleSelectDate(date: string) {
    setSelectedDate(date)
    setViewMode('day')
  }

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
        <SectionHeading>実際の勤務表</SectionHeading>
        <div className="flex gap-2">
          {ATTENDANCE_SUBJECT_VIEW_MODES.map((mode) => (
            <Button
              key={mode.key}
              type="button"
              variant={viewMode === mode.key ? 'primary' : 'secondary'}
              onClick={() => setViewMode(mode.key)}
            >
              {mode.label}
            </Button>
          ))}
        </div>

        <div className="flex flex-col gap-6 rounded-md border border-border p-3">
          {viewMode === 'month' && (
            <MonthlyReferenceView
              userId={subject.user_id}
              restrictToYearMonth={subject.year_month}
              onSelectDate={handleSelectDate}
            />
          )}
          {viewMode === 'week' && (
            <WeeklyReferenceView userId={subject.user_id} initialWeekStart={weekRange.min} weekRange={weekRange} />
          )}
          {viewMode === 'day' && (
            <DailyReferenceView
              key={selectedDate ?? dates[0]}
              userId={subject.user_id}
              initialDate={selectedDate ?? dates[0]}
              dateRange={dateRange}
              onBack={() => setViewMode('month')}
            />
          )}
        </div>
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

function PaidLeaveRequestSubjectView({ subject }: { subject: WorkflowRequestPaidLeaveRequestSubject }) {
  const { label, tone } = paidLeaveRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">対象日</dt>
        <dd className="text-foreground">{subject.target_date ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">種別</dt>
        <dd className="text-foreground">{subject.leave_type_label}</dd>
        <dt className="font-medium text-muted-foreground">日数/時間</dt>
        <dd className="text-foreground">{leaveRequestedAmountLabel(subject)}</dd>
        <dt className="font-medium text-muted-foreground">理由</dt>
        <dd className="text-foreground">{subject.reason ?? '-'}</dd>
      </dl>
    </div>
  )
}

function SpecialLeaveRequestSubjectView({ subject }: { subject: WorkflowRequestSpecialLeaveRequestSubject }) {
  const { label, tone } = paidLeaveRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">対象日</dt>
        <dd className="text-foreground">{subject.target_date ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">休暇種別</dt>
        <dd className="text-foreground">{subject.special_leave_type_name ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">種別</dt>
        <dd className="text-foreground">{subject.leave_type_label}</dd>
        <dt className="font-medium text-muted-foreground">日数/時間</dt>
        <dd className="text-foreground">{leaveRequestedAmountLabel(subject)}</dd>
        <dt className="font-medium text-muted-foreground">理由</dt>
        <dd className="text-foreground">{subject.reason ?? '-'}</dd>
      </dl>
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

      {request.subject_type === 'attendance_month' && request.subject?.type === 'attendance_month' && (
        // key={request.id}: 同一コンポーネントインスタンスのまま別の申請(別の対象月)に
        // 差し替わった場合でも、内部のviewMode/週次weekStart等のstateを確実にリセットする。
        <AttendanceMonthSubjectView key={request.id} subject={request.subject} />
      )}

      {request.subject_type === 'expense_claim' && request.subject?.type === 'expense_claim' && (
        <ExpenseClaimSubjectView subject={request.subject} />
      )}

      {request.subject_type === 'paid_leave_request' && request.subject?.type === 'paid_leave_request' && (
        <PaidLeaveRequestSubjectView subject={request.subject} />
      )}

      {request.subject_type === 'special_leave_request' && request.subject?.type === 'special_leave_request' && (
        <SpecialLeaveRequestSubjectView subject={request.subject} />
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
