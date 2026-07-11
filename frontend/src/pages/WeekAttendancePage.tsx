import { useState } from 'react'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../components/ui/dialog'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { useEditableRows } from '../hooks/useEditableRows'
import {
  useCorrectPunch,
  useDeleteAttendanceDay,
  useDeletePunch,
  usePunches,
  useUpdateAttendanceDay,
  useWeek,
} from '../hooks/useAttendance'
import type { AttendanceDay, AttendancePunch, PunchType } from '../api/types'
import {
  browserOffsetString,
  combineDatetimeLocalWithOffset,
  isoToLocalDatetimeLiteral,
  isoToOffsetString,
  offsetMinutesToString,
} from '../utils/offsetDateTime'
import { attendanceDayStatusLabel, punchStatusLabel, punchTypeLabel } from '../utils/statusLabels'
import { addDays, formatDate, mondayOf, weekDates } from '../utils/weekDates'

const PUNCH_TYPES: PunchType[] = ['clock_in', 'break_start', 'break_end', 'clock_out']

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

/**
 * UC-A013/UC-A014: 打刻ログの訂正・削除。訂正・削除後も行は残り、状態
 * (有効・訂正済み・削除済み)付きで一覧に表示され続ける(打刻ログは追記のみ)。
 */
function PunchLogRow({ punch }: { punch: AttendancePunch }) {
  const [mode, setMode] = useState<'view' | 'correct' | 'delete'>('view')
  const [punchType, setPunchType] = useState<PunchType>(punch.punch_type)
  const [punchedAt, setPunchedAt] = useState(isoToLocalDatetimeLiteral(punch.punched_at))
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const correctPunch = useCorrectPunch()
  const deletePunch = useDeletePunch()
  const { label, tone } = punchStatusLabel(punch.status)

  const startEditing = () => {
    setPunchType(punch.punch_type)
    setPunchedAt(isoToLocalDatetimeLiteral(punch.punched_at))
    setOffset(isoToOffsetString(punch.punched_at))
    setReason('')
    setMode('correct')
  }

  const startDeleting = () => {
    setReason('')
    setMode('delete')
  }

  const handleCorrect = () => {
    const combined = combineDatetimeLocalWithOffset(punchedAt, offset)
    if (!combined) return
    correctPunch.mutate(
      { id: punch.id, input: { punch_type: punchType, punched_at: combined, reason } },
      { onSuccess: () => setMode('view') },
    )
  }

  const handleDelete = () => {
    deletePunch.mutate({ id: punch.id, input: reason }, { onSuccess: () => setMode('view') })
  }

  return (
    <li className="flex flex-col gap-1.5 py-1.5 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium text-foreground">{punchTypeLabel(punch.punch_type)}</span>
        <span className="text-muted-foreground">{isoToLocalDatetimeLiteral(punch.punched_at).replace('T', ' ')}</span>
        <Badge tone={tone}>{label}</Badge>
        {punch.status === 'active' && mode === 'view' && (
          <div className="ml-auto flex gap-1.5">
            <Button variant="secondary" onClick={startEditing}>
              訂正
            </Button>
            <Button variant="danger" onClick={startDeleting}>
              削除
            </Button>
          </div>
        )}
        {punch.status !== 'active' && punch.correction_reason && (
          <span className="text-muted-foreground">理由: {punch.correction_reason}</span>
        )}
      </div>

      {mode === 'correct' && (
        <div className="flex flex-col gap-2 rounded-md border border-border p-2">
          {correctPunch.error && <ErrorMessage error={correctPunch.error} />}
          <div className="flex flex-wrap items-center gap-2">
            <NativeSelect
              aria-label="打刻種別"
              className="w-auto"
              value={punchType}
              onChange={(e) => setPunchType(e.target.value as PunchType)}
            >
              {PUNCH_TYPES.map((type) => (
                <option key={type} value={type}>
                  {punchTypeLabel(type)}
                </option>
              ))}
            </NativeSelect>
            <Input
              type="datetime-local"
              aria-label="訂正後の日時"
              className="w-auto"
              value={punchedAt}
              onChange={(e) => setPunchedAt(e.target.value)}
            />
            <Input
              aria-label="訂正後のオフセット"
              className="w-24"
              value={offset}
              placeholder="+09:00"
              onChange={(e) => setOffset(e.target.value)}
            />
          </div>
          <Input
            aria-label="訂正理由"
            placeholder="訂正理由(必須)"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setMode('view')}>
              キャンセル
            </Button>
            <Button isLoading={correctPunch.isPending} disabled={!reason || !punchedAt} onClick={handleCorrect}>
              訂正を保存
            </Button>
          </div>
        </div>
      )}

      {mode === 'delete' && (
        <div className="flex flex-col gap-2 rounded-md border border-border p-2">
          {deletePunch.error && <ErrorMessage error={deletePunch.error} />}
          <Input
            aria-label="削除理由"
            placeholder="削除理由(必須)"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setMode('view')}>
              キャンセル
            </Button>
            <Button variant="danger" isLoading={deletePunch.isPending} disabled={!reason} onClick={handleDelete}>
              削除する
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}

function PunchLogSection({ date }: { date: string }) {
  const [isOpen, setIsOpen] = useState(false)
  const { data: punches, isLoading } = usePunches(isOpen ? { from: date, to: date } : {})

  return (
    <div className="mt-2">
      <Button variant="secondary" onClick={() => setIsOpen((prev) => !prev)}>
        {isOpen ? '打刻ログを閉じる' : '打刻ログを表示'}
      </Button>

      {isOpen && (
        <div className="mt-2 rounded-lg border border-border p-2">
          {isLoading ? (
            <LoadingState />
          ) : !punches || punches.length === 0 ? (
            <p className="text-xs text-muted-foreground">この日の打刻ログはありません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {punches.map((punch) => (
                <PunchLogRow key={punch.id} punch={punch} />
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

interface DeleteDayDialogProps {
  day: AttendanceDay
}

/** UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能。 */
function DeleteDayDialog({ day }: DeleteDayDialogProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [reason, setReason] = useState('')
  const deleteDay = useDeleteAttendanceDay()

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        setIsOpen(open)
        if (open) {
          setReason('')
          deleteDay.reset()
        }
      }}
    >
      <Button variant="danger" onClick={() => setIsOpen(true)}>
        削除
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>日次勤怠を削除しますか?</DialogTitle>
          <DialogDescription>
            {day.work_date} の日次勤怠を削除します。承認済みの月次に含まれる場合は削除できません。
          </DialogDescription>
        </DialogHeader>
        {deleteDay.error && <ErrorMessage error={deleteDay.error} />}
        <Input
          aria-label="削除理由"
          placeholder="削除理由(必須)"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
        <DialogFooter>
          <Button variant="secondary" onClick={() => setIsOpen(false)}>
            キャンセル
          </Button>
          <Button
            variant="danger"
            isLoading={deleteDay.isPending}
            disabled={!reason}
            onClick={() => deleteDay.mutate({ id: day.id, reason }, { onSuccess: () => setIsOpen(false) })}
          >
            削除する
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
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
          <div className="ml-auto flex gap-2">
            <Button variant="secondary" onClick={startEditing}>
              編集
            </Button>
            <DeleteDayDialog day={day} />
          </div>
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

      <PunchLogSection date={date} />
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
