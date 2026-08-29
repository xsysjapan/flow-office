import type {
  WorkflowRequest,
  WorkflowRequestAttendanceMonthSubject,
  WorkflowRequestCompensatoryLeaveRequestSubject,
  WorkflowRequestExpenseClaimSubject,
  WorkflowRequestPaidLeaveRequestSubject,
  WorkflowRequestShiftSwapRequestSubject,
  WorkflowRequestSpecialLeaveRequestSubject,
} from '../../api/types'
import { AttendanceMonthReferenceTabs } from '../../pages/attendance/AttendanceReferencePage'
import {
  attendanceMonthStatusLabel,
  expenseClaimStatusLabel,
  paidLeaveRequestStatusLabel,
  paymentBearerLabel,
  shiftSwapRequestStatusLabel,
} from '../../utils/statusLabels'
import { Badge } from '../Badge/Badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

/**
 * 残数(*_grants.remaining_days)は承認済み分のみを消化するため、申請中(未承認)の
 * 積み上がりを承認者・本人双方が見落とさないよう内訳を併記する。
 */
function LeaveUsageBreakdownNote({ pendingDays, approvedDays }: { pendingDays: number; approvedDays: number }) {
  return (
    <span className="text-xs text-muted-foreground">
      (うち申請中 {pendingDays}日 / 承認済み {approvedDays}日)
    </span>
  )
}

/** 有給・特別休暇申請の日数/時間表示。時間休(hourly)のみ時間で表示し、それ以外は日数で表示する
 *  (MyPaidLeavePageと同じ考え方)。 */
function leaveRequestedAmountLabel(subject: { leave_type: string; hours: number | null; requested_days: number }): string {
  if (subject.leave_type === 'hourly' && subject.hours !== null) {
    return `${subject.hours}時間`
  }
  return `${subject.requested_days}日`
}

/**
 * 承認者(または申請者自身)が月次勤怠申請の対象社員の実際の勤務表を月次・週次・日次で
 * 確認する。管理画面の勤怠参照機能(AttendanceReferencePage)・バックオフィスタスク詳細と
 * 同一のAttendanceMonthReferenceTabsを使い、表示・CSV/Excel出力・状態変更(締める/締めを
 * 取り消す、権限がある場合のみ)を揃える。レビュー対象はこの申請の年月に限定し、他の月には
 * 遷移できない。
 */
export function AttendanceMonthSubjectView({ subject }: { subject: WorkflowRequestAttendanceMonthSubject }) {
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

      <AttendanceMonthReferenceTabs userId={subject.user_id} yearMonth={subject.year_month} />
    </div>
  )
}

export function ExpenseClaimSubjectView({ subject }: { subject: WorkflowRequestExpenseClaimSubject }) {
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

/** 期間指定の複数日申請であることを承認者・申請者に伝える注記(該当する場合のみ表示)。 */
function RequestGroupNotice({ dates }: { dates: string[] | null }) {
  if (!dates || dates.length <= 1) return null

  return (
    <div className="rounded-md border border-info/40 bg-info/10 p-3 text-sm text-foreground">
      期間指定で{dates.length}日分({dates[0]} 〜 {dates[dates.length - 1]})まとめて申請されています。
      このうち1件を承認すると、残りの日もまとめて承認されます。
    </div>
  )
}

export function PaidLeaveRequestSubjectView({ subject }: { subject: WorkflowRequestPaidLeaveRequestSubject }) {
  const { label, tone } = paidLeaveRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      <RequestGroupNotice dates={subject.request_group_dates} />

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">対象日</dt>
        <dd className="text-foreground">{subject.target_date ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">種別</dt>
        <dd className="text-foreground">{subject.leave_type_label}</dd>
        <dt className="font-medium text-muted-foreground">日数/時間</dt>
        <dd className="text-foreground">{leaveRequestedAmountLabel(subject)}</dd>
        <dt className="font-medium text-muted-foreground">理由</dt>
        <dd className="text-foreground">{subject.reason ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">直近1年間の取得日数</dt>
        <dd className="text-foreground">
          {subject.used_days_last_year}日{' '}
          <LeaveUsageBreakdownNote pendingDays={subject.pending_days_last_year} approvedDays={subject.approved_days_last_year} />
        </dd>
      </dl>
    </div>
  )
}

export function CompensatoryLeaveRequestSubjectView({ subject }: { subject: WorkflowRequestCompensatoryLeaveRequestSubject }) {
  const { label, tone } = paidLeaveRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      <RequestGroupNotice dates={subject.request_group_dates} />

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">対象日</dt>
        <dd className="text-foreground">{subject.target_date ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">種別</dt>
        <dd className="text-foreground">{subject.leave_type_label}</dd>
        <dt className="font-medium text-muted-foreground">日数/時間</dt>
        <dd className="text-foreground">{leaveRequestedAmountLabel(subject)}</dd>
        <dt className="font-medium text-muted-foreground">理由</dt>
        <dd className="text-foreground">{subject.reason ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">直近1年間の取得日数</dt>
        <dd className="text-foreground">
          {subject.used_days_last_year}日{' '}
          <LeaveUsageBreakdownNote pendingDays={subject.pending_days_last_year} approvedDays={subject.approved_days_last_year} />
        </dd>
      </dl>
    </div>
  )
}

export function SpecialLeaveRequestSubjectView({ subject }: { subject: WorkflowRequestSpecialLeaveRequestSubject }) {
  const { label, tone } = paidLeaveRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      <RequestGroupNotice dates={subject.request_group_dates} />

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
        <dt className="font-medium text-muted-foreground">直近1年間の取得日数</dt>
        <dd className="text-foreground">
          {subject.used_days_last_year}日{' '}
          <LeaveUsageBreakdownNote pendingDays={subject.pending_days_last_year} approvedDays={subject.approved_days_last_year} />
        </dd>
      </dl>
    </div>
  )
}

export function ShiftSwapRequestSubjectView({ subject }: { subject: WorkflowRequestShiftSwapRequestSubject }) {
  const { label, tone } = shiftSwapRequestStatusLabel(subject.status)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Badge tone={tone}>{label}</Badge>
      </div>

      {subject.return_comment && (
        <div className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
          差戻し理由: {subject.return_comment}
        </div>
      )}

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">対象日(出勤日)</dt>
        <dd className="text-foreground">{subject.target_date}</dd>
        <dt className="font-medium text-muted-foreground">振替先日(休みになる日)</dt>
        <dd className="text-foreground">{subject.substitute_date}</dd>
        <dt className="font-medium text-muted-foreground">理由</dt>
        <dd className="text-foreground">{subject.reason ?? '-'}</dd>
      </dl>
    </div>
  )
}

export interface WorkflowRequestSubjectDetailProps {
  request: WorkflowRequest
}

/**
 * `request.subject_type`/`request.subject`(GET /workflow-requests/{id}のみに含まれる対象
 * ドメインの実データ)に応じて、有給・代休・特別休暇・振替休日・経費精算・月次勤怠それぞれの
 * 申請内容(対象日・日数・金額・理由等)を、ApprovalsPage(承認画面)と同じ見た目で表示する。
 * 承認・差戻し操作は持たず、閲覧専用(申請の添付資料的な位置づけ)。表示ロジック自体は
 * ApprovalDetailPanelと共有する(このファイルからexportした各SubjectView)。
 * `subject_type`が無い(汎用その他申請)場合や`subject`が未取得の場合はnullを返し、
 * 呼び出し側(WorkflowRequestDetailPage)の既存の入力内容(form_data)表示に委ねる。
 */
export function WorkflowRequestSubjectDetail({ request }: WorkflowRequestSubjectDetailProps) {
  if (request.subject_type === 'attendance_month' && request.subject?.type === 'attendance_month') {
    return <AttendanceMonthSubjectView key={request.id} subject={request.subject} />
  }
  if (request.subject_type === 'expense_claim' && request.subject?.type === 'expense_claim') {
    return <ExpenseClaimSubjectView subject={request.subject} />
  }
  if (request.subject_type === 'paid_leave_request' && request.subject?.type === 'paid_leave_request') {
    return <PaidLeaveRequestSubjectView subject={request.subject} />
  }
  if (request.subject_type === 'special_leave_request' && request.subject?.type === 'special_leave_request') {
    return <SpecialLeaveRequestSubjectView subject={request.subject} />
  }
  if (request.subject_type === 'shift_swap_request' && request.subject?.type === 'shift_swap_request') {
    return <ShiftSwapRequestSubjectView subject={request.subject} />
  }
  if (request.subject_type === 'compensatory_leave_request' && request.subject?.type === 'compensatory_leave_request') {
    return <CompensatoryLeaveRequestSubjectView subject={request.subject} />
  }
  return null
}
