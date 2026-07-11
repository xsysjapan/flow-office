import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { useEditableRows } from '../hooks/useEditableRows'
import { useUpdateAttendanceDay, useWeek } from '../hooks/useAttendance'
import type { AttendanceDay } from '../api/types'
import {
  browserOffsetString,
  combineDatetimeLocalWithOffset,
  isoToLocalDatetimeLiteral,
  offsetMinutesToString,
} from '../utils/offsetDateTime'
import { attendanceDayStatusLabel } from '../utils/statusLabels'
import { addDays, formatDate, mondayOf, weekDates } from '../utils/weekDates'

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

/**
 * 勤務時刻(出勤・退勤・休憩)は、社員本人の既定タイムゾーンではなく、その勤務日自身が
 * 保持するUTCオフセットで表示・編集する(docs/03-architecture.md 3.4)。ブラウザのローカル
 * タイムゾーンには変換しない。
 */
function toDatetimeLocal(iso: string | null | undefined): string {
  return isoToLocalDatetimeLiteral(iso)
}

function dayWarnings(date: string, day: AttendanceDay | undefined, today: string): string[] {
  const warnings: string[] = []
  const isPast = date < today

  if (!day) {
    if (isPast) warnings.push('未入力')
    return warnings
  }

  if (isPast && day.status !== 'clocked_out') warnings.push('打刻漏れ')

  if (day.calculation) {
    const workedMinutes = day.calculation.actual_work_minutes
    const breakMinutes = day.breaks.reduce((sum, b) => {
      if (!b.break_start_at || !b.break_end_at) return sum
      return sum + (new Date(b.break_end_at).getTime() - new Date(b.break_start_at).getTime()) / 60000
    }, 0)

    if (workedMinutes > 480 && breakMinutes < 60) warnings.push('休憩不足')
    else if (workedMinutes > 360 && breakMinutes < 45) warnings.push('休憩不足')

    if (workedMinutes > 600) warnings.push('長時間労働')
  }

  return warnings
}

interface BreakRowData {
  start: string
  end: string
}

interface WeekDayRowProps {
  date: string
  day: AttendanceDay | undefined
  warnings: string[]
}

function WeekDayRow({ date, day, warnings }: WeekDayRowProps) {
  const [isEditing, setIsEditing] = useState(false)
  const [actualStartAt, setActualStartAt] = useState('')
  const [actualEndAt, setActualEndAt] = useState('')
  const [offset, setOffset] = useState('')
  const [workType, setWorkType] = useState('')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const { rows: breakRows, addRow, updateRow, removeRow, reset } = useEditableRows<BreakRowData>([])

  const updateDay = useUpdateAttendanceDay()

  const startEditing = () => {
    if (!day) return
    setActualStartAt(toDatetimeLocal(day.actual_start_at))
    setActualEndAt(toDatetimeLocal(day.actual_end_at))
    setOffset(typeof day.utc_offset_minutes === 'number' ? offsetMinutesToString(day.utc_offset_minutes) : browserOffsetString())
    setWorkType(day.work_type ?? '')
    setNote(day.note ?? '')
    setReason('')
    reset(day.breaks.map((b) => ({ start: toDatetimeLocal(b.break_start_at), end: toDatetimeLocal(b.break_end_at) })))
    setIsEditing(true)
  }

  const dow = new Date(`${date}T00:00:00`).getDay()
  const weekday = WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
  const { label, tone } = day ? attendanceDayStatusLabel(day.status) : { label: '未入力', tone: 'neutral' as const }

  const handleSave = () => {
    if (!day) return
    updateDay.mutate(
      {
        id: day.id,
        input: {
          actual_start_at: combineDatetimeLocalWithOffset(actualStartAt, offset),
          actual_end_at: combineDatetimeLocalWithOffset(actualEndAt, offset),
          breaks: breakRows
            .filter((b) => b.start)
            .map((b) => ({
              start: combineDatetimeLocalWithOffset(b.start, offset) ?? '',
              end: combineDatetimeLocalWithOffset(b.end, offset) ?? undefined,
            })),
          work_type: workType || null,
          note: note || null,
          reason,
        },
      },
      { onSuccess: () => setIsEditing(false) },
    )
  }

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center gap-2.5">
        <span className="min-w-[9rem] text-sm font-semibold text-foreground">
          {date}({weekday})
        </span>
        <Badge tone={tone}>{label}</Badge>
        {warnings.map((warning) => (
          <Badge key={warning} tone="warning">
            {warning}
          </Badge>
        ))}
        {day && !isEditing && (
          <Button variant="secondary" onClick={startEditing} className="ml-auto">
            編集
          </Button>
        )}
      </div>

      {day && !isEditing && (
        <dl className="mt-2 grid grid-cols-[auto_1fr_auto_1fr] gap-x-3 gap-y-1 text-sm">
          <dt className="font-medium text-muted-foreground">出勤</dt>
          <dd className="text-foreground">{day.actual_start_at ? toDatetimeLocal(day.actual_start_at).replace('T', ' ') : '--'}</dd>
          <dt className="font-medium text-muted-foreground">退勤</dt>
          <dd className="text-foreground">{day.actual_end_at ? toDatetimeLocal(day.actual_end_at).replace('T', ' ') : '--'}</dd>
          {typeof day.utc_offset_minutes === 'number' && (day.actual_start_at || day.actual_end_at) && (
            <>
              <dt className="font-medium text-muted-foreground">現地時刻オフセット</dt>
              <dd className="text-foreground">UTC{offsetMinutesToString(day.utc_offset_minutes)}</dd>
            </>
          )}
          {day.calculation && (
            <>
              <dt className="font-medium text-muted-foreground">実働</dt>
              <dd className="text-foreground">{day.calculation.actual_work_minutes}分</dd>
            </>
          )}
        </dl>
      )}

      {isEditing && (
        <div className="mt-3 flex flex-col gap-3 rounded-lg border border-border p-3">
          {updateDay.error && <ErrorMessage error={updateDay.error} />}

          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            出勤
            <Input type="datetime-local" value={actualStartAt} onChange={(e) => setActualStartAt(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            退勤
            <Input type="datetime-local" value={actualEndAt} onChange={(e) => setActualEndAt(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            現地時刻オフセット(海外出張時などに変更)
            <Input
              value={offset}
              placeholder="+09:00"
              pattern="^[+-]\d{2}:\d{2}$"
              onChange={(e) => setOffset(e.target.value)}
            />
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            作業内容
            <Input value={workType} onChange={(e) => setWorkType(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            備考
            <Input value={note} onChange={(e) => setNote(e.target.value)} />
          </label>

          <div className="flex flex-col gap-2">
            <span className="text-xs font-medium text-muted-foreground">休憩</span>
            {breakRows.map((row) => (
              <div key={row.rowId} className="flex flex-wrap items-center gap-2">
                <Input
                  type="datetime-local"
                  aria-label="休憩開始"
                  className="w-auto"
                  value={row.start}
                  onChange={(e) => updateRow(row.rowId, { start: e.target.value })}
                />
                <Input
                  type="datetime-local"
                  aria-label="休憩終了"
                  className="w-auto"
                  value={row.end}
                  onChange={(e) => updateRow(row.rowId, { end: e.target.value })}
                />
                <Button variant="danger" onClick={() => removeRow(row.rowId)}>
                  削除
                </Button>
              </div>
            ))}
            <Button variant="secondary" className="self-start" onClick={() => addRow({ start: '', end: '' })}>
              休憩を追加
            </Button>
          </div>

          <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            修正理由(必須)
            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>

          <div className="flex gap-2 pt-1">
            <Button variant="secondary" onClick={() => setIsEditing(false)}>
              キャンセル
            </Button>
            <Button isLoading={updateDay.isPending} disabled={!reason} onClick={handleSave}>
              保存する
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}

/**
 * UC-A006: 週次勤怠を編集する。日次勤怠(attendance_days)の編集ビューであり、
 * 保存は行ごとに `PUT /attendance/days/{id}` を呼び日次単位の編集イベントに分解される。
 */
export function WeekAttendancePage() {
  const [weekStart, setWeekStart] = useState(() => formatDate(mondayOf(new Date())))
  const { data, isLoading, error } = useWeek(weekStart)

  const today = formatDate(new Date())
  const dates = weekDates(weekStart)
  const daysByDate = new Map((data ?? []).map((day) => [day.work_date, day]))

  return (
    <Card
      title="週次勤怠"
      actions={
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setWeekStart((prev) => addDays(prev, -7))}>
            前週
          </Button>
          <Button variant="secondary" onClick={() => setWeekStart(formatDate(mondayOf(new Date())))}>
            今週
          </Button>
          <Button variant="secondary" onClick={() => setWeekStart((prev) => addDays(prev, 7))}>
            次週
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
        <ul className="divide-y divide-border">
          {dates.map((date) => (
            <WeekDayRow key={date} date={date} day={daysByDate.get(date)} warnings={dayWarnings(date, daysByDate.get(date), today)} />
          ))}
        </ul>
      )}
    </Card>
  )
}
