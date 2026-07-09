import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useEditableRows } from '../hooks/useEditableRows'
import { useUpdateAttendanceDay, useWeek } from '../hooks/useAttendance'
import type { AttendanceDay } from '../api/types'
import { attendanceDayStatusLabel } from '../utils/statusLabels'
import { addDays, formatDate, mondayOf, weekDates } from '../utils/weekDates'
import './WeekAttendancePage.css'

const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

function toDatetimeLocal(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
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
  const [workType, setWorkType] = useState('')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const { rows: breakRows, addRow, updateRow, removeRow, reset } = useEditableRows<BreakRowData>([])

  const updateDay = useUpdateAttendanceDay()

  const startEditing = () => {
    if (!day) return
    setActualStartAt(toDatetimeLocal(day.actual_start_at))
    setActualEndAt(toDatetimeLocal(day.actual_end_at))
    setWorkType(day.work_type ?? '')
    setNote(day.note ?? '')
    setReason('')
    reset(day.breaks.map((b) => ({ start: toDatetimeLocal(b.break_start_at), end: toDatetimeLocal(b.break_end_at) })))
    setIsEditing(true)
  }

  const handleSave = () => {
    if (!day) return
    updateDay.mutate(
      {
        id: day.id,
        input: {
          actual_start_at: actualStartAt || null,
          actual_end_at: actualEndAt || null,
          breaks: breakRows.filter((b) => b.start).map((b) => ({ start: b.start, end: b.end || undefined })),
          work_type: workType || null,
          note: note || null,
          reason,
        },
      },
      { onSuccess: () => setIsEditing(false) },
    )
  }

  const dow = new Date(`${date}T00:00:00`).getDay()
  const weekday = WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
  const { label, tone } = day ? attendanceDayStatusLabel(day.status) : { label: '未入力', tone: 'neutral' as const }

  return (
    <li className="week-attendance__day">
      <div className="week-attendance__day-header">
        <span className="week-attendance__date">
          {date}({weekday})
        </span>
        <Badge tone={tone}>{label}</Badge>
        {warnings.map((warning) => (
          <Badge key={warning} tone="warning">
            {warning}
          </Badge>
        ))}
        {day && !isEditing && (
          <Button variant="secondary" onClick={startEditing}>
            編集
          </Button>
        )}
      </div>

      {day && !isEditing && (
        <dl className="week-attendance__summary">
          <dt>出勤</dt>
          <dd>{day.actual_start_at ? toDatetimeLocal(day.actual_start_at).replace('T', ' ') : '--'}</dd>
          <dt>退勤</dt>
          <dd>{day.actual_end_at ? toDatetimeLocal(day.actual_end_at).replace('T', ' ') : '--'}</dd>
          {day.calculation && (
            <>
              <dt>実働</dt>
              <dd>{day.calculation.actual_work_minutes}分</dd>
            </>
          )}
        </dl>
      )}

      {isEditing && (
        <div className="week-attendance__edit-form">
          {updateDay.error && <ErrorMessage error={updateDay.error} />}

          <label>
            出勤
            <input type="datetime-local" value={actualStartAt} onChange={(e) => setActualStartAt(e.target.value)} />
          </label>
          <label>
            退勤
            <input type="datetime-local" value={actualEndAt} onChange={(e) => setActualEndAt(e.target.value)} />
          </label>
          <label>
            作業内容
            <input value={workType} onChange={(e) => setWorkType(e.target.value)} />
          </label>
          <label>
            備考
            <input value={note} onChange={(e) => setNote(e.target.value)} />
          </label>

          <div className="week-attendance__breaks">
            <span>休憩</span>
            {breakRows.map((row) => (
              <div key={row.rowId} className="week-attendance__break-row">
                <input
                  type="datetime-local"
                  aria-label="休憩開始"
                  value={row.start}
                  onChange={(e) => updateRow(row.rowId, { start: e.target.value })}
                />
                <input
                  type="datetime-local"
                  aria-label="休憩終了"
                  value={row.end}
                  onChange={(e) => updateRow(row.rowId, { end: e.target.value })}
                />
                <Button variant="danger" onClick={() => removeRow(row.rowId)}>
                  削除
                </Button>
              </div>
            ))}
            <Button variant="secondary" onClick={() => addRow({ start: '', end: '' })}>
              休憩を追加
            </Button>
          </div>

          <label>
            修正理由(必須)
            <input value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>

          <div className="week-attendance__edit-actions">
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
        <div className="week-attendance__nav">
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
      <p className="week-attendance__range">
        {dates[0]} 〜 {dates[6]}
      </p>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="週次勤怠の取得に失敗しました。" />
      ) : (
        <ul className="week-attendance__days">
          {dates.map((date) => (
            <WeekDayRow key={date} date={date} day={daysByDate.get(date)} warnings={dayWarnings(date, daysByDate.get(date), today)} />
          ))}
        </ul>
      )}
    </Card>
  )
}
