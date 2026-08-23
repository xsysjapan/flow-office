import { useState } from 'react'
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttendanceCalculationSummary } from '../../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { AttendanceDayRow } from '../../components/AttendanceDayRow/AttendanceDayRow'
import { AttendanceSelectionActionBar } from '../../components/AttendanceSelectionActionBar/AttendanceSelectionActionBar'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { WeeklyAttendanceBulkEntryModal } from '../../components/WeeklyAttendanceBulkEntryModal/WeeklyAttendanceBulkEntryModal'
import { useAllocateWeekOvertime, useWeek, useWeekOvertime } from '../../hooks/useAttendance'
import { useShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { useSpecialLeaveTypes } from '../../hooks/useSpecialLeave'
import { dayWarnings } from '../../utils/attendanceDayWarnings'
import { weeklyAttendanceTotals } from '../../utils/attendanceWeeklyTotals'
import { addDays, formatDate, mondayOf, weekDates } from '../../utils/weekDates'

/**
 * UC-A006: 週次勤怠を編集する。日次勤怠(attendance_days)の編集ビューであり、独立データ
 * としては持たない。各日を選ぶと日次画面(実績の作成・編集・削除・打刻履歴)に遷移する
 * (オブジェクト指向UI)。日次画面から「週次」で戻ってきた場合、その日が属する週を
 * `?start=`(週初め)で指定できる(未指定なら今週)。
 *
 * 「選択」ボタンでiOS Mailライクな選択モードに入ると、各行がチェックボックス表示になり、
 * 複数日を選んで有給休暇・特別休暇・代休の申請へまとめて遷移できる(選択した日は
 * `?dates=`にカンマ区切りで渡し、各申請画面側でプレフィルする)。
 */
export function WeekAttendancePage() {
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const startParam = searchParams.get('start')
  const [weekStart, setWeekStart] = useState(() =>
    formatDate(mondayOf(startParam ? new Date(`${startParam}T00:00:00`) : new Date())),
  )
  const [bulkEntryMessage, setBulkEntryMessage] = useState<string | null>(null)
  const [selectionMode, setSelectionMode] = useState(false)
  const [selectedDates, setSelectedDates] = useState<Set<string>>(new Set())
  const [allocationDraft, setAllocationDraft] = useState<Record<string, { prescribed: number; nonPrescribed: number }>>({})
  const { data, isLoading, error } = useWeek(weekStart)
  const { data: weeklyOvertime } = useWeekOvertime(weekStart)
  const allocateWeeklyOvertime = useAllocateWeekOvertime(weekStart)
  const { data: specialLeaveTypes } = useSpecialLeaveTypes(
    user?.effective_features === undefined || user.effective_features.includes('paid_leave.requests'),
  )
  const hasSpecialLeaveTypes = (specialLeaveTypes ?? []).some((type) => type.is_active)

  const today = formatDate(new Date())
  const currentWeekStart = formatDate(mondayOf(new Date()))
  const dates = weekDates(weekStart)
  const { data: schedule } = useShiftAssignments(user?.id ?? '', dates[0], dates[6])
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))
  const scheduleByDate = new Map((schedule ?? []).map((entry) => [entry.work_date, entry]))
  const { totals: weeklyTotals, absenceDays, specialLeaveBreakdown } = weeklyAttendanceTotals(data ?? [])
  const unallocatedWeeklyMinutes = weeklyOvertime?.unallocated_weekly_statutory_excess_overtime_minutes ?? 0

  function applySuggestedAllocation() {
    let remaining = unallocatedWeeklyMinutes
    const allocations = (data ?? []).map((day) => {
      const capacity = day.calculation?.non_prescribed_statutory_within_work_minutes ?? 0
      const minutes = Math.min(capacity, remaining)
      remaining -= minutes
      return {
        attendance_day_id: day.id,
        prescribed_minutes: 0,
        non_prescribed_minutes: minutes,
        late_night_prescribed_minutes: 0,
        late_night_non_prescribed_minutes: Math.max(
          0,
          minutes - (capacity - (day.calculation?.late_night_non_prescribed_statutory_within_work_minutes ?? 0)),
        ),
      }
    }).filter((allocation) => allocation.non_prescribed_minutes > 0)
    if (remaining === 0) allocateWeeklyOvertime.mutate(allocations)
  }

  function applyManualAllocation() {
    const allocations = (data ?? []).map((day) => {
      const prescribed = allocationDraft[day.id]?.prescribed ?? 0
      const nonPrescribed = allocationDraft[day.id]?.nonPrescribed ?? 0
      const prescribedCapacity = day.calculation?.prescribed_statutory_within_work_minutes ?? 0
      const nonPrescribedCapacity = day.calculation?.non_prescribed_statutory_within_work_minutes ?? 0
      return {
        attendance_day_id: day.id,
        prescribed_minutes: prescribed,
        non_prescribed_minutes: nonPrescribed,
        // 日中時間を先に振り替え、日中だけでは足りない分を深夜内数として自動設定する。
        late_night_prescribed_minutes: Math.max(0, prescribed - (prescribedCapacity - (day.calculation?.late_night_prescribed_statutory_within_work_minutes ?? 0))),
        late_night_non_prescribed_minutes: Math.max(0, nonPrescribed - (nonPrescribedCapacity - (day.calculation?.late_night_non_prescribed_statutory_within_work_minutes ?? 0))),
      }
    }).filter((allocation) => allocation.prescribed_minutes + allocation.non_prescribed_minutes > 0)
    allocateWeeklyOvertime.mutate(allocations)
  }

  const suggestedCapacity = (data ?? []).reduce(
    (total, day) => total + (day.calculation?.non_prescribed_statutory_within_work_minutes ?? 0), 0,
  )
  const draftedMinutes = Object.values(allocationDraft).reduce(
    (total, value) => total + value.prescribed + value.nonPrescribed, 0,
  )

  function toggleDate(date: string) {
    setSelectedDates((prev) => {
      const next = new Set(prev)
      if (next.has(date)) next.delete(date)
      else next.add(date)
      return next
    })
  }

  function exitSelectionMode() {
    setSelectionMode(false)
    setSelectedDates(new Set())
  }

  const sortedSelectedDates = Array.from(selectedDates).sort()
  const datesQuery = sortedSelectedDates.join(',')

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="週次勤怠"
        navigation={
          <div className="flex gap-2">
            <Button variant="secondary" size="icon" title="前週" aria-label="前週" onClick={() => setWeekStart((prev) => addDays(prev, -7))}>
              <ChevronLeft aria-hidden="true" />
            </Button>
            <Button variant="secondary" disabled={weekStart === currentWeekStart} onClick={() => setWeekStart(currentWeekStart)}>
              今週
            </Button>
            <Button asChild variant="secondary" title="月次で見る">
              <Link to={`/attendance/months/${weekStart.slice(0, 7)}`}>
                <CalendarRange aria-hidden="true" />
                月次
              </Link>
            </Button>
            <Button variant="secondary" size="icon" title="次週" aria-label="次週" onClick={() => setWeekStart((prev) => addDays(prev, 7))}>
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
            {unallocatedWeeklyMinutes > 0 && (
              <div className="mb-4 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-foreground">
                <p className="font-medium">週40時間超の法定外労働時間が{Math.floor(unallocatedWeeklyMinutes / 60)}時間{unallocatedWeeklyMinutes % 60 || ''}{unallocatedWeeklyMinutes % 60 ? '分' : ''}未振分です。</p>
                <p className="mt-1 text-muted-foreground">所定外法定内労働を優先して法定外へ移します。所定内労働を移す場合は対象日を選択してください。</p>
                {suggestedCapacity >= unallocatedWeeklyMinutes && (
                  <Button className="mt-2" size="sm" variant="secondary" onClick={applySuggestedAllocation}>
                    推奨案（所定外を優先）を適用
                  </Button>
                )}
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full text-left text-xs">
                    <thead><tr><th className="py-1">作業日</th><th>所定外から振替（分）</th><th>所定内から振替（分）</th></tr></thead>
                    <tbody>{(data ?? []).filter((day) => day.calculation).map((day) => {
                      const nonPrescribedCapacity = day.calculation?.non_prescribed_statutory_within_work_minutes ?? 0
                      const prescribedCapacity = day.calculation?.prescribed_statutory_within_work_minutes ?? 0
                      return <tr key={`allocation-${day.id}`}>
                        <td className="py-1 pr-2">{day.work_date}</td>
                        <td className="pr-2"><input className="w-24 rounded border border-border bg-background px-2 py-1" type="number" min={0} max={nonPrescribedCapacity} value={allocationDraft[day.id]?.nonPrescribed ?? 0} onChange={(e) => setAllocationDraft((draft) => ({ ...draft, [day.id]: { prescribed: draft[day.id]?.prescribed ?? 0, nonPrescribed: Number(e.target.value) } }))} /></td>
                        <td><input className="w-24 rounded border border-border bg-background px-2 py-1" type="number" min={0} max={prescribedCapacity} value={allocationDraft[day.id]?.prescribed ?? 0} onChange={(e) => setAllocationDraft((draft) => ({ ...draft, [day.id]: { nonPrescribed: draft[day.id]?.nonPrescribed ?? 0, prescribed: Number(e.target.value) } }))} /></td>
                      </tr>
                    })}</tbody>
                  </table>
                </div>
                <Button className="mt-2" size="sm" disabled={draftedMinutes !== unallocatedWeeklyMinutes} onClick={applyManualAllocation}>
                  手動振分を適用（{draftedMinutes}/{unallocatedWeeklyMinutes}分）
                </Button>
                {allocateWeeklyOvertime.error && <ErrorMessage error={allocateWeeklyOvertime.error} />}
              </div>
            )}
            <AttendanceCalculationSummary
              title="今週の集計"
              totals={weeklyTotals}
              weeklyStatutoryExcessOvertimeMinutes={weeklyOvertime?.weekly_statutory_excess_overtime_minutes}
              absenceDays={absenceDays}
              specialLeaveBreakdown={specialLeaveBreakdown}
              showAllLeaveTotals
            />
          </div>
        )}
      </Card>

      {!isLoading && !error && (
        <Card
          title="日別の内訳"
          actions={
            selectionMode ? (
              // モバイル幅ではタイトルとの横並び(flex-wrap)に任せると、選択件数+ボタン群の
              // 幅がタイトルを圧迫してタイトルの文字が中途半端に折り返される崩れが起きるため、
              // `sm`未満ではここではなく直下(Cardの子要素側)に独立したブロックとして表示する。
              <div className="hidden sm:block">
                <AttendanceSelectionActionBar
                  selectedCount={selectedDates.size}
                  hasSpecialLeaveTypes={hasSpecialLeaveTypes}
                  datesQuery={datesQuery}
                  onCancel={exitSelectionMode}
                />
              </div>
            ) : (
              <div className="flex items-center gap-3">
                {bulkEntryMessage && <Badge tone="success">{bulkEntryMessage}</Badge>}
                <Button variant="secondary" onClick={() => setSelectionMode(true)}>
                  選択
                </Button>
                <WeeklyAttendanceBulkEntryModal
                  defaultFrom={dates[0]}
                  defaultTo={dates[6]}
                  onCompleted={setBulkEntryMessage}
                />
              </div>
            )
          }
        >
          {selectionMode && (
            <div className="mb-3 sm:hidden">
              <AttendanceSelectionActionBar
                selectedCount={selectedDates.size}
                hasSpecialLeaveTypes={hasSpecialLeaveTypes}
                datesQuery={datesQuery}
                onCancel={exitSelectionMode}
              />
            </div>
          )}
          <ul className="divide-y divide-border">
            {dates.map((date) => (
              <AttendanceDayRow
                key={date}
                date={date}
                day={daysByDate.get(date)}
                schedule={scheduleByDate.get(date)}
                warnings={dayWarnings(date, daysByDate.get(date), today)}
                selectionMode={selectionMode}
                selected={selectedDates.has(date)}
                onToggleSelected={toggleDate}
              />
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
