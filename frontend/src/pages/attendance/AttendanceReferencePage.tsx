import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttendanceCalculationSummary } from '../../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { Duration } from '../../components/Duration/Duration'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Textarea } from '../../components/ui/textarea'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import type { AttendanceDay, EmployeeShiftAssignment } from '../../api/types'
import {
  useAttendanceMonth,
  useCloseMonth,
  useDownloadAttendanceCsv,
  useDownloadAttendanceExcel,
  usePunches,
  useReopenMonth,
  useWeek,
} from '../../hooks/useAttendance'
import { useShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { dayWarnings } from '../../utils/attendanceDayWarnings'
import { specialLeaveTypeBreakdown, weeklyAttendanceTotals } from '../../utils/attendanceWeeklyTotals'
import { isoToLocalDatetimeLiteral, isoToTimeLiteral } from '../../utils/offsetDateTime'
import {
  attendanceMonthStatusLabel,
  attendanceRowDisplayLabel,
  legalHolidayWarningLabel,
  punchStatusLabel,
  punchTypeLabel,
} from '../../utils/statusLabels'
import { addDays, addMonths, datesInMonth, formatDate, mondayOf, weekDates } from '../../utils/weekDates'

export type ViewMode = 'month' | 'week' | 'day'

const VIEW_MODES: Array<{ key: ViewMode; label: string }> = [
  { key: 'month', label: '月次' },
  { key: 'week', label: '週次' },
  { key: 'day', label: '日次' },
]

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function weekdayLabel(date: string): string {
  const dow = new Date(`${date}T00:00:00`).getDay()
  return WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
}

/** 月次・週次一覧の1日分。既定では参照専用(リンクにしない)。`onSelect`を渡すと行全体を
 *  クリックできるようにする(承認待ち一覧からの実際の勤務表確認など、対象日への遷移が必要な場合)。 */
function ReadOnlyDayRow({
  date,
  day,
  schedule,
  onSelect,
}: {
  date: string
  day: AttendanceDay | undefined
  schedule?: EmployeeShiftAssignment
  onSelect?: (date: string) => void
}) {
  const { label, tone } = attendanceRowDisplayLabel(day, schedule)
  const warnings = dayWarnings(date, day, formatDate(new Date()))

  const content = (
    <>
      <div className="flex min-w-0 items-center gap-2 sm:contents">
        <span className="whitespace-nowrap text-sm font-semibold text-foreground">
          {date}({weekdayLabel(date)})
        </span>
        <Badge tone={tone}>{label}</Badge>
        {schedule?.public_holiday_name && (
          <span className="text-xs text-muted-foreground">{schedule.public_holiday_name}</span>
        )}
      </div>
      <div className="col-start-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground sm:contents">
        {day && (day.actual_start_at || day.actual_end_at) && (
          <span className="whitespace-nowrap text-sm">
            {isoToTimeLiteral(day.actual_start_at) || '--:--'} 〜 {isoToTimeLiteral(day.actual_end_at) || '--:--'}
          </span>
        )}
        {day?.calculation && (
          <span className="whitespace-nowrap text-sm">
            労働時間 <Duration minutes={day.calculation.work_minutes} />
          </span>
        )}
        {warnings.map((warning) => (
          <Badge key={warning} tone="warning">
            {warning}
          </Badge>
        ))}
      </div>
    </>
  )

  if (onSelect) {
    return (
      <li>
        <button
          type="button"
          onClick={() => onSelect(date)}
          className="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 rounded-md px-2 py-3 text-left transition-colors hover:bg-accent sm:flex sm:items-center sm:gap-2.5"
        >
          {content}
        </button>
      </li>
    )
  }

  return (
    <li className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 px-2 py-3 sm:flex sm:items-center sm:gap-2.5">
      {content}
    </li>
  )
}

/** 「締めを取り消す」押下で開き、取消理由を入力してから確定するダイアログ(UC-A017・管理者専用の
 *  救済コマンド)。勤怠参照画面とバックオフィスタスク詳細画面の両方から呼べるようexportする。 */
export function ReopenMonthDialog({ monthId, yearMonth }: { monthId: string; yearMonth: string }) {
  const [reason, setReason] = useState('')
  const reopenMonth = useReopenMonth()

  return (
    <ConfirmActionDialog
      triggerLabel="締めを取り消す"
      triggerVariant="danger"
      title="月次勤怠の締めを取り消しますか?"
      description={`${yearMonth}の月次勤怠の締めを取り消します。取消理由は必須です。締めを取り消しても、日次勤怠のロック状態(承認済み時点のロック)は変わりません。`}
      confirmLabel="締めを取り消す"
      isPending={reopenMonth.isPending}
      error={reopenMonth.error}
      onOpenChange={(open) => {
        if (open) {
          setReason('')
          reopenMonth.reset()
        }
      }}
      onConfirm={() => reopenMonth.mutateAsync({ id: monthId, reason })}
    >
      <FormField label="取消理由" htmlFor="reopen-month-reason">
        <Textarea id="reopen-month-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
      </FormField>
    </ConfirmActionDialog>
  )
}

export function MonthlyReferenceView({
  userId,
  yearMonth,
  onSelectDate,
}: {
  userId: string
  /** 表示対象の年月。前月・次月への移動は呼び出し側(ページ上部の年月選択)が担う。 */
  yearMonth: string
  /** 指定すると、日別の内訳の各行をクリックできるようにし、選んだ日付を通知する。 */
  onSelectDate?: (date: string) => void
}) {
  const { user } = useAuth()
  const canReopenMonth = user?.effective_permissions?.includes('attendance.month_reopen') ?? false
  const canCloseMonth = user?.effective_permissions?.includes('backoffice_task.execute') ?? false
  const canExport = user?.effective_permissions?.includes('attendance.export') ?? false
  const { data, isLoading, error } = useAttendanceMonth(yearMonth, userId)
  const closeMonth = useCloseMonth()
  const downloadCsv = useDownloadAttendanceCsv()
  const downloadExcel = useDownloadAttendanceExcel()

  const month = data?.month
  const monthMeta = month ? attendanceMonthStatusLabel(month.status) : null
  const daysByDate = new Map((data?.days ?? []).map((day) => [day.work_date, day]))
  const scheduleByDate = new Map((data?.schedule ?? []).map((entry) => [entry.work_date, entry]))
  const dates = datesInMonth(yearMonth)

  return (
    <>
      <Card
        title="月次勤怠"
        actions={monthMeta && <Badge tone={monthMeta.tone}>{monthMeta.label}</Badge>}
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

            {data?.monthly_calculation_totals && (
              <div className="border-t border-border pt-4">
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
        <Card title="日別の内訳">
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <ReadOnlyDayRow key={date} date={date} day={daysByDate.get(date)} schedule={scheduleByDate.get(date)} onSelect={onSelectDate} />
            ))}
          </ul>
        </Card>
      )}

      {!isLoading && !error && month && canExport && (
        <Card title="出力">
          {downloadCsv.error && <ErrorMessage error={downloadCsv.error} fallback="勤怠CSVの取得に失敗しました。" />}
          {downloadExcel.error && <ErrorMessage error={downloadExcel.error} fallback="勤怠Excelの取得に失敗しました。" />}
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              isLoading={downloadCsv.isPending}
              onClick={() => downloadCsv.mutate({ year_month: [month.year_month], user_id: [month.user_id], format: 'generic' })}
            >
              CSV出力
            </Button>
            <Button
              variant="secondary"
              isLoading={downloadExcel.isPending}
              onClick={() => downloadExcel.mutate({ year_month: [month.year_month], user_id: [month.user_id] })}
            >
              Excel出力
            </Button>
          </div>
        </Card>
      )}

      {/* 参照専用の月次勤怠カードとは分離し、状態を変更する管理者操作であることを明示する
       *  (UC-A011・UC-A017)。締めるにはbackoffice_task.execute権限、締めを取り消すには
       *  専用の権限attendance.month_reopenが必要(月次勤怠締め取消は他の権限に流用しない)。 */}
      {!isLoading && !error && month && (
        (month.status === 'closed' && canReopenMonth) || (month.status !== 'closed' && canCloseMonth)
      ) && (
        <Card title="管理者操作">
          {month.status === 'closed' ? (
            <ReopenMonthDialog monthId={month.id} yearMonth={yearMonth} />
          ) : (
            <ConfirmActionDialog
              triggerLabel="締める"
              triggerVariant="primary"
              title="月次勤怠を締めますか?"
              description={`${yearMonth}の月次勤怠を締めます。締めた後は日次実績を編集できなくなります。この操作は元に戻せません。`}
              confirmLabel="締めを確定する"
              isPending={closeMonth.isPending}
              error={closeMonth.error}
              onConfirm={() => closeMonth.mutateAsync(month.id)}
            />
          )}
        </Card>
      )}
    </>
  )
}

export function WeeklyReferenceView({
  userId,
  initialWeekStart,
  weekRange,
}: {
  userId: string
  initialWeekStart?: string
  /** 指定すると、前週・次週への移動をこの範囲内(両端とも週初=月曜日、含む)に限定する
   *  (承認レビューで対象月の範囲外に遷移できないようにする用途)。 */
  weekRange?: { min: string; max: string }
}) {
  const [weekStart, setWeekStart] = useState(() => initialWeekStart ?? formatDate(mondayOf(new Date())))
  const currentWeekStart = formatDate(mondayOf(new Date()))
  const { data, isLoading, error } = useWeek(weekStart, userId)

  const dates = weekDates(weekStart)
  const { data: schedule } = useShiftAssignments(userId, dates[0], dates[6])
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))
  const scheduleByDate = new Map((schedule ?? []).map((entry) => [entry.work_date, entry]))
  const { totals, absenceDays, specialLeaveBreakdown } = weeklyAttendanceTotals(data ?? [])

  return (
    <>
      <Card
        title="週次勤怠"
        navigation={
          <div className="flex gap-2">
            <Button
              variant="secondary"
              size="icon"
              title="前週"
              aria-label="前週"
              disabled={weekRange !== undefined && weekStart <= weekRange.min}
              onClick={() => setWeekStart((prev) => addDays(prev, -7))}
            >
              <ChevronLeft aria-hidden="true" />
            </Button>
            {weekRange === undefined && (
              <Button variant="secondary" disabled={weekStart === currentWeekStart} onClick={() => setWeekStart(currentWeekStart)}>
                今週
              </Button>
            )}
            <Button
              variant="secondary"
              size="icon"
              title="次週"
              aria-label="次週"
              disabled={weekRange !== undefined && weekStart >= weekRange.max}
              onClick={() => setWeekStart((prev) => addDays(prev, 7))}
            >
              <ChevronRight aria-hidden="true" />
            </Button>
          </div>
        }
      >
        <p className="mb-3 text-sm text-muted-foreground">
          {dates[0]} 〜 {dates[6]}
        </p>

        {isLoading ? (
          <LoadingState />
        ) : error ? (
          <ErrorMessage error={error} fallback="週次勤怠の取得に失敗しました。" />
        ) : (
          <div className="border-t border-border pt-4">
            <AttendanceCalculationSummary
              title="今週の集計"
              totals={totals}
              absenceDays={absenceDays}
              specialLeaveBreakdown={specialLeaveBreakdown}
              showAllLeaveTotals
            />
          </div>
        )}
      </Card>

      {!isLoading && !error && (
        <Card title="日別の内訳">
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <ReadOnlyDayRow key={date} date={date} day={daysByDate.get(date)} schedule={scheduleByDate.get(date)} />
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}

/** 参照専用の打刻ログ(訂正・削除等の操作は行わない。承認前の勤務実態確認用)。 */
function ReadOnlyPunchLogCard({ date, userId }: { date: string; userId: string }) {
  const { data: punches, isLoading } = usePunches({ from: date, to: date, userId })
  const activePunches = punches?.filter((punch) => punch.status === 'active')

  return (
    <Card title="打刻ログ">
      {isLoading ? (
        <LoadingState />
      ) : !activePunches || activePunches.length === 0 ? (
        <EmptyState title="この日の打刻ログはありません。" />
      ) : (
        <ul className="divide-y divide-border">
          {activePunches.map((punch) => {
            const { label, tone } = punchStatusLabel(punch.status)
            return (
              <li key={punch.id} className="flex flex-wrap items-center gap-2 py-1.5 text-xs">
                <span className="font-medium text-foreground">{punchTypeLabel(punch.punch_type)}</span>
                <span className="text-muted-foreground">{isoToLocalDatetimeLiteral(punch.punched_at).replace('T', ' ')}</span>
                <Badge tone={tone}>{label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

export function DailyReferenceView({
  userId,
  initialDate,
  dateRange,
  onBack,
}: {
  userId: string
  initialDate?: string
  /** 指定すると、前日・翌日への移動をこの範囲内(両端含む)に限定する(承認レビューで
   *  対象月の範囲外に遷移できないようにする用途)。 */
  dateRange?: { min: string; max: string }
  /** 指定すると、ナビゲーションに戻るボタンを表示する(月次一覧へ戻る等)。 */
  onBack?: () => void
}) {
  const [date, setDate] = useState(() => initialDate ?? formatDate(new Date()))
  const today = formatDate(new Date())
  const monday = formatDate(mondayOf(new Date(`${date}T00:00:00`)))
  const { data, isLoading, error } = useWeek(monday, userId)
  const { data: scheduleDays } = useShiftAssignments(userId, monday, addDays(monday, 6))
  const day = data?.find((d) => d.work_date === date)
  const schedule = scheduleDays?.find((entry) => entry.work_date === date)
  const statusMeta = day || schedule ? attendanceRowDisplayLabel(day, schedule) : null

  return (
    <>
    <Card
      title="日次勤怠"
      actions={statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
      navigation={
        <div className="flex gap-2">
          {onBack && (
            <Button variant="secondary" onClick={onBack}>
              <ChevronLeft aria-hidden="true" />
              月次に戻る
            </Button>
          )}
          <Button
            variant="secondary"
            size="icon"
            title="前日"
            aria-label="前日"
            disabled={dateRange !== undefined && date <= dateRange.min}
            onClick={() => setDate((prev) => addDays(prev, -1))}
          >
            <ChevronLeft aria-hidden="true" />
          </Button>
          {dateRange === undefined && (
            <Button variant="secondary" disabled={date === today} onClick={() => setDate(today)}>
              今日
            </Button>
          )}
          <Button
            variant="secondary"
            size="icon"
            title="翌日"
            aria-label="翌日"
            disabled={dateRange !== undefined && date >= dateRange.max}
            onClick={() => setDate((prev) => addDays(prev, 1))}
          >
            <ChevronRight aria-hidden="true" />
          </Button>
        </div>
      }
    >
      <p className="mb-3 text-sm text-muted-foreground">
        {date}({weekdayLabel(date)})
      </p>
      {schedule?.public_holiday_name && (
        <p className="mb-3 text-sm text-muted-foreground">{schedule.public_holiday_name}</p>
      )}

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="日次勤怠の取得に失敗しました。" />
      ) : !day ? (
        <EmptyState title="この日の勤怠記録はありません。" />
      ) : (
        <div className="flex flex-col gap-4 border-t border-border pt-4">
          <dl className="grid grid-cols-[auto_1fr_auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            {day.planned_start_at && (
              <>
                <dt className="font-medium text-muted-foreground">勤務予定</dt>
                <dd className="text-foreground">
                  {isoToTimeLiteral(day.planned_start_at) || '--:--'} 〜 {isoToTimeLiteral(day.planned_end_at) || '--:--'}
                </dd>
              </>
            )}
            <dt className="font-medium text-muted-foreground">出勤</dt>
            <dd className="text-foreground">{isoToTimeLiteral(day.actual_start_at) || '--:--'}</dd>
            <dt className="font-medium text-muted-foreground">退勤</dt>
            <dd className="text-foreground">{isoToTimeLiteral(day.actual_end_at) || '--:--'}</dd>
          </dl>

          {day.breaks.length > 0 && (
            <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
              {day.breaks.map((b) => (
                <li key={b.id}>
                  休憩 {isoToTimeLiteral(b.break_start_at) || '--:--'} 〜 {isoToTimeLiteral(b.break_end_at) || '--:--'}
                </li>
              ))}
            </ul>
          )}

          {!!day.leave_segments?.length && (
            <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
              {day.leave_segments.map((segment) => (
                <li key={segment.id}>
                  遅刻・早退 {isoToTimeLiteral(segment.start_at) || '--:--'} 〜 {isoToTimeLiteral(segment.end_at) || '--:--'}
                  {segment.note && ` (${segment.note})`}
                </li>
              ))}
            </ul>
          )}

          {(day.work_type || day.note) && (
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
              {day.work_type && (
                <>
                  <dt className="font-medium text-muted-foreground">作業内容</dt>
                  <dd className="text-foreground">{day.work_type}</dd>
                </>
              )}
              {day.note && (
                <>
                  <dt className="font-medium text-muted-foreground">備考</dt>
                  <dd className="text-foreground">{day.note}</dd>
                </>
              )}
            </dl>
          )}

          {day.calculation && (
            <AttendanceCalculationSummary
              title="この日の集計"
              totals={day.calculation}
              absenceDays={day.calculation.absence_minutes ? 1 : undefined}
              specialLeaveBreakdown={specialLeaveTypeBreakdown([day])}
            />
          )}
        </div>
      )}
      </Card>
      <ReadOnlyPunchLogCard date={date} userId={userId} />
    </>
  )
}

/** Read-only month/week/day drill-down shared by approval and back-office reviews. */
export function AttendanceMonthReferenceTabs({ userId, yearMonth }: { userId: string; yearMonth: string }) {
  const [viewMode, setViewMode] = useState<ViewMode>('month')
  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const dates = datesInMonth(yearMonth)
  const dateRange = { min: dates[0], max: dates[dates.length - 1] }
  const weekRange = {
    min: formatDate(mondayOf(new Date(`${dates[0]}T00:00:00`))),
    max: formatDate(mondayOf(new Date(`${dates[dates.length - 1]}T00:00:00`))),
  }

  function selectDate(date: string) {
    setSelectedDate(date)
    setViewMode('day')
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex gap-2">
        {VIEW_MODES.map((mode) => (
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
      {viewMode === 'month' && (
        <MonthlyReferenceView userId={userId} yearMonth={yearMonth} onSelectDate={selectDate} />
      )}
      {viewMode === 'week' && (
        <WeeklyReferenceView userId={userId} initialWeekStart={weekRange.min} weekRange={weekRange} />
      )}
      {viewMode === 'day' && (
        <DailyReferenceView
          key={selectedDate ?? dates[0]}
          userId={userId}
          initialDate={selectedDate ?? dates[0]}
          dateRange={dateRange}
          onBack={() => setViewMode('month')}
        />
      )}
    </div>
  )
}

/**
 * 管理者が自分以外の社員の勤怠を月次・週次・日次で参照する画面(閲覧専用。編集は行わない)。
 * 対象社員・対象年月は`UserPicker`・`YearMonthPicker`で選び(画面上部に配置)、選択後は
 * `AttendanceMonthReferenceTabs`(承認画面・バックオフィスタスク詳細・申請詳細と共通の
 * 月次/週次/日次タブ切り替えコンポーネント)を表示する。週次・日次への切り替えやCSV/Excel出力・
 * 状態変更(締める/締めを取り消す、権限がある場合)の挙動は他の画面と完全に同一になる。
 * 対象社員・対象年月はURLに反映し、Browser Back・リロード・URL共有で状態を維持する
 * (`ui-interaction-patterns` §2.10)。月次/週次/日次のタブ切り替え自体は他の画面と同様、
 * 既存のナビゲーション的な状態としてURL化しない。
 */
export function AttendanceReferencePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userId = searchParams.get('user') ?? undefined
  const yearMonthParam = searchParams.get('yearMonth')
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const yearMonth = yearMonthParam && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonthParam) ? yearMonthParam : currentYearMonth

  const setUserId = (id: string | undefined) => {
    const next = new URLSearchParams(searchParams)
    if (id) next.set('user', id)
    else next.delete('user')
    setSearchParams(next)
  }

  const setYearMonth = (ym: string) => {
    const next = new URLSearchParams(searchParams)
    next.set('yearMonth', ym)
    setSearchParams(next)
  }

  return (
    <div className="flex flex-col gap-6">
      <Card title="勤怠参照">
        <div className="flex flex-wrap items-end gap-4">
          <div className="max-w-sm flex-1">
            <FormField label="対象社員" htmlFor="attendance-reference-user">
              <UserPicker id="attendance-reference-user" value={userId} onChange={setUserId} />
            </FormField>
          </div>
          <div className="mb-4 flex gap-2">
            <Button variant="secondary" size="icon" title="前月" aria-label="前月" onClick={() => setYearMonth(addMonths(yearMonth, -1))}>
              <ChevronLeft aria-hidden="true" />
            </Button>
            <YearMonthPicker id="attendance-reference-year-month" value={yearMonth} onChange={(ym) => ym && setYearMonth(ym)} />
            <Button variant="secondary" disabled={yearMonth === currentYearMonth} onClick={() => setYearMonth(currentYearMonth)}>
              今月
            </Button>
            <Button variant="secondary" size="icon" title="次月" aria-label="次月" onClick={() => setYearMonth(addMonths(yearMonth, 1))}>
              <ChevronRight aria-hidden="true" />
            </Button>
          </div>
        </div>
      </Card>

      {userId !== undefined && <AttendanceMonthReferenceTabs userId={userId} yearMonth={yearMonth} />}
    </div>
  )
}
