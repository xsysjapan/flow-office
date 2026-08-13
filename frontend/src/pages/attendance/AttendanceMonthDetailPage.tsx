import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { useAppSettings } from '../../contexts/useAppSettings'
import { AttendanceCalculationSummary } from '../../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { AttendanceDayRow } from '../../components/AttendanceDayRow/AttendanceDayRow'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import {
  CancelApprovedLeaveDialog,
  type ApprovedLeaveTarget,
} from '../../components/CancelApprovedLeaveDialog/CancelApprovedLeaveDialog'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { MonthlyAttendanceBulkEntryModal } from '../../components/MonthlyAttendanceBulkEntryModal/MonthlyAttendanceBulkEntryModal'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog'
import { useAttendanceMonth, useSubmitMonth } from '../../hooks/useAttendance'
import { useMyCompensatoryLeaveRequests } from '../../hooks/useCompensatoryLeave'
import { useMyPaidLeaveRequests } from '../../hooks/usePaidLeave'
import { useMySpecialLeaveRequests } from '../../hooks/useSpecialLeave'
import { dayWarnings } from '../../utils/attendanceDayWarnings'
import { employmentYearMonths } from '../../utils/employmentPeriod'
import { attendanceMonthStatusLabel, legalHolidayWarningLabel } from '../../utils/statusLabels'
import { datesInMonth, formatDate } from '../../utils/weekDates'

/**
 * 在籍期間内の全月を前後移動の対象にする。月別の働き方割当や実績の有無は、
 * 月次勤怠の閲覧可否に影響しない。
 */
function useNavigableYearMonths(yearMonth: string) {
  const { user } = useAuth()
  const currentYearMonth = formatDate(new Date()).slice(0, 7)

  const navigable = employmentYearMonths(user?.hire_date, user?.termination_date, currentYearMonth)

  const prevMonth = [...navigable].reverse().find((ym) => ym < yearMonth)
  const nextMonth = navigable.find((ym) => ym > yearMonth)

  return { prevMonth, nextMonth }
}

function MonthNav({ yearMonth, canSubmit }: { yearMonth: string; canSubmit: boolean }) {
  const navigate = useNavigate()
  const { prevMonth, nextMonth } = useNavigableYearMonths(yearMonth)
  const currentYearMonth = formatDate(new Date()).slice(0, 7)

  return (
    <div className="flex gap-2">
      <Button
        variant="secondary"
        size="icon"
        title={prevMonth ? '前月' : '入社月より前の月には移動できません'}
        aria-label="前月"
        disabled={!prevMonth}
        onClick={() => prevMonth && navigate(`/attendance/months/${prevMonth}`)}
      >
        <ChevronLeft aria-hidden="true" />
      </Button>
      {yearMonth === currentYearMonth ? (
        <Button variant="secondary" disabled>
          今月
        </Button>
      ) : (
        <Button asChild variant="secondary">
          <Link to={`/attendance/months/${currentYearMonth}`}>今月</Link>
        </Button>
      )}
      <Button asChild variant="secondary" title="月次一覧へ戻る">
        <Link to="/attendance/months">
          <CalendarRange aria-hidden="true" />
          一覧
        </Link>
      </Button>
      <Button
        variant="secondary"
        size="icon"
        title={nextMonth ? '次月' : '退社月または今月より先の月には移動できません'}
        aria-label="次月"
        disabled={!nextMonth}
        onClick={() => nextMonth && navigate(`/attendance/months/${nextMonth}`)}
      >
        <ChevronRight aria-hidden="true" />
      </Button>
      {canSubmit && <SubmitMonthDialog yearMonth={yearMonth} />}
    </div>
  )
}

/** 「提出する」押下で開き、提出先の承認者を選んでから確定するモーダル。 */
function SubmitMonthDialog({ yearMonth }: { yearMonth: string }) {
  const { systemSettings } = useAppSettings()
  const approvalRequired = systemSettings.attendance_requires_approval

  const [isOpen, setIsOpen] = useState(false)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const submitMonth = useSubmitMonth(yearMonth)

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        setIsOpen(open)
        if (open) {
          setApproverUserId(undefined)
          submitMonth.reset()
        }
      }}
    >
      <Button onClick={() => setIsOpen(true)}>提出する</Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>月次勤怠を提出しますか?</DialogTitle>
          <DialogDescription>
            {yearMonth} の月次勤怠を提出します。提出先の承認者を選んでください。提出すると、承認・差戻しされるまでこの月の日次勤怠・打刻ログは編集できなくなります。
            対象月に有給申請がある場合は、先に有給申請の承認を完了してください。
            {!approvalRequired && '現在の設定では承認者の指定は不要です。提出すると同時に確定します。'}
          </DialogDescription>
        </DialogHeader>
        {submitMonth.error && <ErrorMessage error={submitMonth.error} />}
        <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
        {!approvalRequired && (
          <p className="text-xs text-muted-foreground">承認者の指定は任意です。</p>
        )}
        <DialogFooter>
          <Button variant="secondary" onClick={() => setIsOpen(false)}>
            キャンセル
          </Button>
          <Button
            isLoading={submitMonth.isPending}
            disabled={approvalRequired && !approverUserId}
            onClick={() =>
              submitMonth.mutate(approverUserId, {
                onSuccess: () => setIsOpen(false),
              })
            }
          >
            提出する
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * UC-A007: 月次勤怠を確認する。日別の内訳を一覧表示し、問題がある日は行を選んで
 * 日次画面(実績の作成・編集・打刻履歴)に遷移できる(オブジェクト指向UI)。
 * 前月・次月への移動は在籍期間内の全月で行える。
 */
export function AttendanceMonthDetailPage() {
  const { yearMonth } = useParams<{ yearMonth: string }>()
  const queryClient = useQueryClient()
  const [cancelTarget, setCancelTarget] = useState<ApprovedLeaveTarget | null>(null)
  const { data, isLoading, error } = useAttendanceMonth(yearMonth ?? '')
  const { data: paidLeaveRequests } = useMyPaidLeaveRequests()
  const { data: specialLeaveRequests } = useMySpecialLeaveRequests()
  const { data: compensatoryLeaveRequests } = useMyCompensatoryLeaveRequests()

  if (!yearMonth) return null

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  // attendance_monthsの行は初回提出時に初めて作られるため、month === null(取得済みで
  // レコード無し)は「未提出」を意味する。month === undefined(取得中)はまだ判定できない
  // ため、提出ボタンはナビにあり isLoading では隠れないので明示的に除外する。
  const canSubmit =
    !isLoading &&
    yearMonth <= currentYearMonth &&
    (month === null || month?.status === 'not_submitted' || month?.status === 'returned')
  const bulkEntryLocked = month != null && (month.status === 'submitted' || month.status === 'approved' || month.status === 'closed')
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const dates = datesInMonth(yearMonth)
  const today = formatDate(new Date())

  const approvedRequestFor = (requests: { target_date: string; status: string; id: string }[] | undefined, date: string) =>
    requests?.find((r) => r.target_date === date && r.status === 'approved')?.id

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="月次勤怠"
        actions={monthMeta && <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge>}
        navigation={<MonthNav yearMonth={yearMonth} canSubmit={canSubmit} />}
      >
        <p className="mb-3 text-sm text-muted-foreground">{yearMonth}</p>

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="月次勤怠の取得に失敗しました。" />
        ) : (
          <>
        {month && month.legal_holiday_warnings.length > 0 && (
          <div className="mb-3 flex flex-wrap gap-2">
            {month.legal_holiday_warnings.map((warning) => (
              <Badge key={`${warning.rule}-${warning.period_start}`} tone="warning">
                {legalHolidayWarningLabel(warning)}
              </Badge>
            ))}
          </div>
        )}

        {month?.status === 'returned' && month.return_comment && (
          <div className="mb-3 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
            差戻し理由: {month.return_comment}
          </div>
        )}

        {data?.monthly_calculation_totals && (
          <div className="mt-4 border-t border-border pt-4">
            <AttendanceCalculationSummary
              title="今月の集計"
              totals={data.monthly_calculation_totals}
              statutoryExcessOver60hMinutes={data.monthly_calculation_totals.statutory_excess_overtime_over_60h_minutes}
              weeklyStatutoryExcessOvertimeMinutes={data.monthly_calculation_totals.weekly_statutory_excess_overtime_minutes}
              absenceDays={data.monthly_calculation_totals.absence_days ?? 0}
              specialLeaveBreakdown={data.special_leave_breakdown}
              showAllLeaveTotals
            />
          </div>
        )}
          </>
        )}
      </Card>

      {!isLoading && !error && (
        <Card title="日別の内訳" actions={<MonthlyAttendanceBulkEntryModal yearMonth={yearMonth} disabled={bulkEntryLocked} />}>
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <AttendanceDayRow
                key={date}
                date={date}
                day={daysByDate.get(date)}
                warnings={dayWarnings(date, daysByDate.get(date), today)}
                approvedPaidLeaveRequestId={approvedRequestFor(paidLeaveRequests, date)}
                approvedSpecialLeaveRequestId={approvedRequestFor(specialLeaveRequests, date)}
                approvedCompensatoryLeaveRequestId={approvedRequestFor(compensatoryLeaveRequests, date)}
                onRequestCancelApprovedLeave={setCancelTarget}
              />
            ))}
          </ul>
        </Card>
      )}

      <CancelApprovedLeaveDialog
        target={cancelTarget}
        onOpenChange={(open) => !open && setCancelTarget(null)}
        onCancelled={() => {
          setCancelTarget(null)
          void queryClient.invalidateQueries({ queryKey: ['attendance'] })
        }}
      />
    </div>
  )
}
