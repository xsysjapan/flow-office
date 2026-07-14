import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
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
import type { AttendanceDay, AttendancePunch, PunchType } from '../api/types'
import { useEditableRows } from '../hooks/useEditableRows'
import {
  useCorrectPunch,
  useCreateAttendanceDay,
  useDeleteAttendanceDay,
  useDeletePunch,
  usePunches,
  useUpdateAttendanceDay,
  useWeek,
} from '../hooks/useAttendance'
import {
  browserOffsetString,
  combineDatetimeLocalWithOffset,
  isoToLocalDatetimeLiteral,
  isoToOffsetString,
  isoToTimeLiteral,
  offsetMinutesToString,
} from '../utils/offsetDateTime'
import { attendanceDayStatusLabel, punchStatusLabel, punchTypeLabel } from '../utils/statusLabels'
import { formatDate, mondayOf } from '../utils/weekDates'

const PUNCH_TYPES: PunchType[] = ['clock_in', 'break_start', 'break_end', 'clock_out']
const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

function weekdayLabel(date: string): string {
  const dow = new Date(`${date}T00:00:00`).getDay()
  return WEEKDAY_LABELS[dow === 0 ? 6 : dow - 1]
}

/** 勤務時刻はその勤務日自身のUTCオフセットで編集する(docs/03-architecture.md 3.4)。 */
function toDatetimeLocal(iso: string | null | undefined): string {
  return isoToLocalDatetimeLiteral(iso)
}

interface BreakRowData {
  start: string
  end: string
}

function buildBreaksPayload(rows: BreakRowData[], offset: string) {
  return rows
    .filter((b) => b.start)
    .map((b) => ({
      start: combineDatetimeLocalWithOffset(b.start, offset) ?? '',
      end: combineDatetimeLocalWithOffset(b.end, offset) ?? undefined,
    }))
}

function BreakRowsEditor({
  rows,
  onAdd,
  onUpdate,
  onRemove,
}: {
  rows: ReturnType<typeof useEditableRows<BreakRowData>>['rows']
  onAdd: () => void
  onUpdate: (rowId: number, patch: Partial<BreakRowData>) => void
  onRemove: (rowId: number) => void
}) {
  return (
    <div className="flex flex-col gap-2">
      <span className="text-xs font-medium text-muted-foreground">休憩</span>
      {rows.map((row) => (
        <div key={row.rowId} className="flex flex-wrap items-center gap-2">
          <Input
            type="datetime-local"
            aria-label="休憩開始"
            className="w-auto"
            value={row.start}
            onChange={(e) => onUpdate(row.rowId, { start: e.target.value })}
          />
          <Input
            type="datetime-local"
            aria-label="休憩終了"
            className="w-auto"
            value={row.end}
            onChange={(e) => onUpdate(row.rowId, { end: e.target.value })}
          />
          <Button variant="danger" onClick={() => onRemove(row.rowId)}>
            削除
          </Button>
        </div>
      ))}
      <Button variant="secondary" className="self-start" onClick={onAdd}>
        休憩を追加
      </Button>
    </div>
  )
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

function PunchLogCard({ date }: { date: string }) {
  const { data: punches, isLoading } = usePunches({ from: date, to: date })

  return (
    <Card title="打刻ログ">
      {isLoading ? (
        <LoadingState />
      ) : !punches || punches.length === 0 ? (
        <p className="text-sm text-muted-foreground">この日の打刻ログはありません。</p>
      ) : (
        <ul className="divide-y divide-border">
          {punches.map((punch) => (
            <PunchLogRow key={punch.id} punch={punch} />
          ))}
        </ul>
      )}
    </Card>
  )
}

/** UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能。 */
function DeleteDayDialog({ day, onDeleted }: { day: AttendanceDay; onDeleted: () => void }) {
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
            onClick={() => deleteDay.mutate({ id: day.id, reason }, { onSuccess: () => onDeleted() })}
          >
            削除する
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function DayEditForm({ day, onDone }: { day: AttendanceDay; onDone: () => void }) {
  const [actualStartAt, setActualStartAt] = useState(toDatetimeLocal(day.actual_start_at))
  const [actualEndAt, setActualEndAt] = useState(toDatetimeLocal(day.actual_end_at))
  const [offset, setOffset] = useState(
    typeof day.utc_offset_minutes === 'number' ? offsetMinutesToString(day.utc_offset_minutes) : browserOffsetString(),
  )
  const [workType, setWorkType] = useState(day.work_type ?? '')
  const [note, setNote] = useState(day.note ?? '')
  const [reason, setReason] = useState('')
  const { rows, addRow, updateRow, removeRow } = useEditableRows<BreakRowData>(
    day.breaks.map((b) => ({ start: toDatetimeLocal(b.break_start_at), end: toDatetimeLocal(b.break_end_at) })),
  )
  const updateDay = useUpdateAttendanceDay()

  const handleSave = () => {
    updateDay.mutate(
      {
        id: day.id,
        input: {
          actual_start_at: combineDatetimeLocalWithOffset(actualStartAt, offset),
          actual_end_at: combineDatetimeLocalWithOffset(actualEndAt, offset),
          breaks: buildBreaksPayload(rows, offset),
          work_type: workType || null,
          note: note || null,
          reason,
        },
      },
      { onSuccess: () => onDone() },
    )
  }

  return (
    <div className="flex flex-col gap-3">
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
        <Input value={offset} placeholder="+09:00" pattern="^[+-]\d{2}:\d{2}$" onChange={(e) => setOffset(e.target.value)} />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        作業内容
        <Input value={workType} onChange={(e) => setWorkType(e.target.value)} />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        備考
        <Input value={note} onChange={(e) => setNote(e.target.value)} />
      </label>

      <BreakRowsEditor rows={rows} onAdd={() => addRow({ start: '', end: '' })} onUpdate={updateRow} onRemove={removeRow} />

      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        修正理由(必須)
        <Input value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <div className="flex gap-2 pt-1">
        <Button variant="secondary" onClick={onDone}>
          キャンセル
        </Button>
        <Button isLoading={updateDay.isPending} disabled={!reason} onClick={handleSave}>
          保存する
        </Button>
      </div>
    </div>
  )
}

/** UC-A016: 出勤日を新規作成する。打刻の有無にかかわらず、月が締められるまではいつでも作成できる。 */
function DayCreateForm({ date }: { date: string }) {
  const { user } = useAuth()
  const [actualStartAt, setActualStartAt] = useState('')
  const [actualEndAt, setActualEndAt] = useState('')
  const [offset, setOffset] = useState(browserOffsetString())
  const [workType, setWorkType] = useState('')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const { rows, addRow, updateRow, removeRow } = useEditableRows<BreakRowData>([])
  const createDay = useCreateAttendanceDay()

  const handleCreate = () => {
    if (!user) return
    createDay.mutate({
      user_id: user.id,
      work_date: date,
      actual_start_at: combineDatetimeLocalWithOffset(actualStartAt, offset),
      actual_end_at: combineDatetimeLocalWithOffset(actualEndAt, offset),
      breaks: buildBreaksPayload(rows, offset),
      work_type: workType || null,
      note: note || null,
      reason,
    })
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">この日の勤怠記録はまだありません。実績を入力して作成できます。</p>
      {createDay.error && <ErrorMessage error={createDay.error} />}
      {createDay.isSuccess && <p className="text-sm text-success">実績を作成しました。</p>}

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
        <Input value={offset} placeholder="+09:00" pattern="^[+-]\d{2}:\d{2}$" onChange={(e) => setOffset(e.target.value)} />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        作業内容
        <Input value={workType} onChange={(e) => setWorkType(e.target.value)} />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        備考
        <Input value={note} onChange={(e) => setNote(e.target.value)} />
      </label>

      <BreakRowsEditor rows={rows} onAdd={() => addRow({ start: '', end: '' })} onUpdate={updateRow} onRemove={removeRow} />

      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        作成理由(必須)
        <Input value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <Button className="self-start" isLoading={createDay.isPending} disabled={!reason} onClick={handleCreate}>
        作成する
      </Button>
    </div>
  )
}

/**
 * 日次勤怠画面。週次・月次画面から対象の日を選んで遷移する(オブジェクト指向UI)。
 * 実績の作成(UC-A016)・編集(UC-A005)・削除(UC-A015)と、当日の打刻履歴(UC-A012〜A014)を
 * 1画面にまとめ、任意の勤務日の実績を直接入力できるようにする。
 */
export function AttendanceDayPage() {
  const { date } = useParams<{ date: string }>()
  const navigate = useNavigate()
  const [isEditing, setIsEditing] = useState(false)

  const monday = date ? formatDate(mondayOf(new Date(`${date}T00:00:00`))) : ''
  const { data: weekDays, isLoading, error } = useWeek(monday)

  if (!date) return null
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="日次勤怠の取得に失敗しました。" />

  const day = weekDays?.find((d) => d.work_date === date)
  const statusMeta = day ? attendanceDayStatusLabel(day.status) : null

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={`${date}(${weekdayLabel(date)})の勤怠`}
        actions={statusMeta ? <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge> : undefined}
      >
        {day && !isEditing && (
          <div className="flex flex-col gap-4">
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

            {day.calculation && (
              <dl className="grid grid-cols-[auto_1fr_auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                <dt className="font-medium text-muted-foreground">所定労働時間</dt>
                <dd className="text-foreground">{day.calculation.prescribed_work_minutes}分</dd>
                <dt className="font-medium text-muted-foreground">実働</dt>
                <dd className="text-foreground">{day.calculation.actual_work_minutes}分</dd>

                <dt className="font-medium text-muted-foreground">所定内残業</dt>
                <dd className="text-foreground">{day.calculation.non_statutory_overtime_minutes}分</dd>
                <dt className="font-medium text-muted-foreground">法定外残業</dt>
                <dd className="text-foreground">{day.calculation.statutory_overtime_minutes}分</dd>

                <dt className="font-medium text-muted-foreground">深夜労働</dt>
                <dd className="text-foreground">{day.calculation.late_night_minutes}分</dd>
                <dt className="font-medium text-muted-foreground">深夜(所定内労働)</dt>
                <dd className="text-foreground">{day.calculation.regular_work_late_night_minutes}分</dd>

                <dt className="font-medium text-muted-foreground">深夜(所定内残業)</dt>
                <dd className="text-foreground">{day.calculation.non_statutory_overtime_late_night_minutes}分</dd>
                <dt className="font-medium text-muted-foreground">法定外深夜</dt>
                <dd className="text-foreground">{day.calculation.statutory_overtime_late_night_minutes}分</dd>

                <dt className="font-medium text-muted-foreground">法定休日労働</dt>
                <dd className="text-foreground">{day.calculation.legal_holiday_work_minutes}分</dd>
                <dt className="font-medium text-muted-foreground">所定休日労働</dt>
                <dd className="text-foreground">{day.calculation.company_holiday_work_minutes}分</dd>

                <dt className="font-medium text-muted-foreground">法定休日深夜</dt>
                <dd className="text-foreground">{day.calculation.legal_holiday_late_night_minutes}分</dd>
              </dl>
            )}

            {day.monthly_overtime && (
              <p className="text-xs text-muted-foreground">
                今月の法定外残業累計(参考): {day.monthly_overtime.cumulative_statutory_overtime_minutes}分
                (うち月60時間超残業: {day.monthly_overtime.statutory_overtime_over_60h_minutes}分)
              </p>
            )}

            {day.breaks.length > 0 && (
              <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                {day.breaks.map((b) => (
                  <li key={b.id}>
                    休憩 {isoToTimeLiteral(b.break_start_at) || '--:--'} 〜 {isoToTimeLiteral(b.break_end_at) || '--:--'}
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

            <div className="flex gap-2 border-t border-border pt-4">
              <Button variant="secondary" onClick={() => setIsEditing(true)}>
                編集
              </Button>
              <DeleteDayDialog day={day} onDeleted={() => navigate(-1)} />
            </div>
          </div>
        )}

        {day && isEditing && <DayEditForm day={day} onDone={() => setIsEditing(false)} />}

        {!day && <DayCreateForm date={date} />}
      </Card>

      <PunchLogCard date={date} />
    </div>
  )
}
