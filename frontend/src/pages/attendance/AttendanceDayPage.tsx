import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarRange, ChevronLeft, ChevronRight, MoreVertical } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttendanceCalculationSummary } from '../../components/AttendanceCalculationSummary/AttendanceCalculationSummary'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import {
  CancelApprovedLeaveDialog,
  type ApprovedLeaveTarget,
} from '../../components/CancelApprovedLeaveDialog/CancelApprovedLeaveDialog'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { Duration } from '../../components/Duration/Duration'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { DateTimePicker } from '../../components/DateTimePicker/DateTimePicker'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type {
  AttendanceDay,
  AttendanceDayDefaults,
  AttendancePunch,
  CompensatoryLeaveRequest,
  PaidLeaveRequest,
  PaidLeaveType,
  PunchType,
  SpecialLeaveRequest,
  WorkLocationType,
} from '../../api/types'
import type { AttendanceDayPunchLogAction } from '../../api/attendance'
import { useAppSettings } from '../../contexts/useAppSettings'
import {
  useCancelCompensatoryLeaveRequest,
  useCreateCompensatoryLeaveRequest,
  useMyCompensatoryLeaveRequests,
} from '../../hooks/useCompensatoryLeave'
import { useEditableRows } from '../../hooks/useEditableRows'
import {
  useAdjustAttendanceDailyCalculation,
  useAttendanceDayDefaults,
  useAttendanceMonth,
  useCorrectPunch,
  useCreateAttendanceDay,
  useCreatePunch,
  useDeleteAttendanceDay,
  useDeletePunch,
  usePunches,
  useUpdateAttendanceDay,
  useWeek,
} from '../../hooks/useAttendance'
import { useShiftAssignments } from '../../hooks/useEmployeeShiftAssignments'
import { useCancelPaidLeaveRequest, useCreatePaidLeaveRequest, useMyPaidLeaveRequests } from '../../hooks/usePaidLeave'
import { useCreateShiftSwapRequest } from '../../hooks/useShiftSwap'
import {
  useCancelSpecialLeaveRequest,
  useCreateSpecialLeaveRequest,
  useMySpecialLeaveRequests,
} from '../../hooks/useSpecialLeave'
import { breakShortfallWarning } from '../../utils/attendanceDayWarnings'
import { specialLeaveTypeBreakdown } from '../../utils/attendanceWeeklyTotals'
import {
  browserOffsetString,
  combineDatetimeLocalWithOffset,
  isoToLocalDatetimeLiteral,
  isoToOffsetString,
  isoToTimeLiteral,
  offsetMinutesToString,
} from '../../utils/offsetDateTime'
import {
  attendanceDayDisplayLabel,
  punchStatusLabel,
  punchTypeLabel,
  WORK_LOCATION_TYPE_OPTIONS,
  workLocationTypeLabel,
} from '../../utils/statusLabels'
import { addDays, formatDate, mondayOf } from '../../utils/weekDates'

const DAY_DEFAULTS_SOURCE_LABEL: Record<AttendanceDayDefaults['source'], string | null> = {
  punch: '打刻内容を初期値として反映しました(働き方の丸め単位で丸めています)。',
  schedule: '勤務予定(休憩を含む)を初期値として反映しました。',
  system_default: 'システムの初期設定を初期値として反映しました。',
  none: null,
}

const PUNCH_TYPES: PunchType[] = ['clock_in', 'break_start', 'break_end', 'clock_out']
const WEEKDAY_LABELS = ['月', '火', '水', '木', '金', '土', '日']

/** 月次勤怠が提出済み以降(提出済み・承認済み・締め済み)かどうか。バックエンドの
 *  `AttendanceEditGuard::BLOCKED_MONTH_STATUSES` と対応する(差戻し・未提出・月次未作成は含めない)。 */
function useMonthLocked(date: string): boolean {
  const { data } = useAttendanceMonth(date.slice(0, 7))
  const status = data?.month?.status
  return status === 'submitted' || status === 'approved' || status === 'closed'
}

function MonthLockedNotice() {
  return (
    <p className="text-sm text-muted-foreground">
      月次勤怠が提出済みのため、この日は編集できません。修正が必要な場合は修正申請を利用してください。
    </p>
  )
}

/**
 * 編集中に変更が失われるおそれがある間、タブを閉じる・リロードする操作に対してブラウザ標準の
 * 確認を出す(`ui-interaction-patterns` §2.9)。react-router-domはBrowserRouter(データ
 * ルーターではない)構成のため`useBlocker`が使えず、アプリ内Link遷移(前日・翌日・週次等)を
 * 個別にブロックする仕組みは今回のスコープでは追加しない(大掛かりなルーティング変更が
 * 必要になるため見送り。完了報告に記載)。
 */
function useUnsavedChangesGuard(active: boolean) {
  useEffect(() => {
    if (!active) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [active])
}

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

/** `<input type="datetime-local">` の2値の差分(分)。片方でも空なら0とする。 */
function diffMinutes(startLiteral: string, endLiteral: string): number {
  if (!startLiteral || !endLiteral) return 0
  const diff = (new Date(endLiteral).getTime() - new Date(startLiteral).getTime()) / 60000
  return diff > 0 ? diff : 0
}

/**
 * 保存前の入力値(出勤・退勤・休憩行)から、労基法34条の休憩不足警告を算出する
 * (労働時間6時間超で休憩45分未満、労働時間8時間超で休憩60分未満)。警告は保存をブロックしない。
 */
function useBreakShortfallWarning(actualStartAt: string, actualEndAt: string, rows: BreakRowData[]): string | null {
  const breakMinutes = rows.reduce((sum, row) => sum + diffMinutes(row.start, row.end), 0)
  const workedMinutes = Math.max(0, diffMinutes(actualStartAt, actualEndAt) - breakMinutes)
  return breakShortfallWarning(workedMinutes, breakMinutes)
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
          <DateTimePicker
            aria-label="休憩開始"
            value={row.start || undefined}
            onChange={(value) => onUpdate(row.rowId, { start: value ?? '' })}
          />
          <DateTimePicker
            aria-label="休憩終了"
            value={row.end || undefined}
            onChange={(value) => onUpdate(row.rowId, { end: value ?? '' })}
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

interface LeaveSegmentRowData {
  start: string
  end: string
  note: string
}

function buildLeaveSegmentsPayload(rows: LeaveSegmentRowData[], offset: string) {
  return rows
    .filter((row) => row.start && row.end)
    .map((row) => ({
      start: combineDatetimeLocalWithOffset(row.start, offset) ?? '',
      end: combineDatetimeLocalWithOffset(row.end, offset) ?? '',
      note: row.note || null,
    }))
}

/**
 * 遅刻・早退等を欠勤時間として扱う区間(有給休暇・特別休暇は既存の申請・承認機能で扱う)。
 * 実績(出勤・退勤)の内側・外側どちらの時間帯も入力できる(docs/07-usecases-attendance.md
 * 「不就労時間の処理区分」参照)。
 */
function LeaveSegmentsEditor({
  rows,
  onAdd,
  onUpdate,
  onRemove,
}: {
  rows: ReturnType<typeof useEditableRows<LeaveSegmentRowData>>['rows']
  onAdd: () => void
  onUpdate: (rowId: number, patch: Partial<LeaveSegmentRowData>) => void
  onRemove: (rowId: number) => void
}) {
  return (
    <div className="flex flex-col gap-2">
      <span className="text-xs font-medium text-muted-foreground">遅刻・早退</span>
      {rows.map((row) => (
        <div key={row.rowId} className="flex flex-wrap items-center gap-2">
          <DateTimePicker
            aria-label="遅刻・早退開始"
            value={row.start || undefined}
            onChange={(value) => onUpdate(row.rowId, { start: value ?? '' })}
          />
          <DateTimePicker
            aria-label="遅刻・早退終了"
            value={row.end || undefined}
            onChange={(value) => onUpdate(row.rowId, { end: value ?? '' })}
          />
          <Input
            aria-label="遅刻・早退の備考"
            placeholder="備考"
            className="w-auto"
            value={row.note}
            onChange={(e) => onUpdate(row.rowId, { note: e.target.value })}
          />
          <Button variant="danger" onClick={() => onRemove(row.rowId)}>
            削除
          </Button>
        </div>
      ))}
      <Button variant="secondary" className="self-start" onClick={onAdd}>
        遅刻・早退を追加
      </Button>
    </div>
  )
}

/** UC-A013/UC-A014: 編集モードでは、訂正・削除済みの監査ログも含めて表示する。 */
function PunchLogRow({ punch, isEdited, isEditing }: { punch: AttendancePunch; isEdited: boolean; isEditing: boolean }) {
  const [mode, setMode] = useState<'view' | 'correct' | 'delete'>('view')
  const [punchType, setPunchType] = useState<PunchType>(punch.punch_type)
  const [punchedAt, setPunchedAt] = useState(isoToLocalDatetimeLiteral(punch.punched_at))
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const correctPunch = useCorrectPunch()
  const deletePunch = useDeletePunch()
  const { label, tone } = punchStatusLabel(punch.status)

  useEffect(() => {
    if (!isEditing) setMode('view')
  }, [isEditing])

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
        {isEdited && <span className="text-muted-foreground">(編集済)</span>}
        <Badge tone={tone}>{label}</Badge>
        {isEditing && punch.status === 'active' && mode === 'view' && (
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
            <DateTimePicker aria-label="訂正後の日時" value={punchedAt || undefined} onChange={(value) => setPunchedAt(value ?? '')} />
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

function PunchAddForm({ date }: { date: string }) {
  const [punchType, setPunchType] = useState<PunchType>('clock_in')
  const [punchedAt, setPunchedAt] = useState('')
  const [offset, setOffset] = useState(browserOffsetString())
  const createPunch = useCreatePunch()

  const handleSubmit = () => {
    const combined = combineDatetimeLocalWithOffset(punchedAt, offset)
    if (!combined) return
    createPunch.mutate(
      { work_date: date, punch_type: punchType, punched_at: combined, source: 'web' },
      { onSuccess: () => setPunchedAt('') },
    )
  }

  return (
    <div className="flex flex-col gap-2 border-t border-border pt-3">
      {createPunch.error && <ErrorMessage error={createPunch.error} />}
      <div className="flex flex-wrap items-center gap-2">
        <NativeSelect
          aria-label="追加する打刻種別"
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
        <DateTimePicker aria-label="追加する日時" value={punchedAt || undefined} onChange={(value) => setPunchedAt(value ?? '')} />
        <Input
          aria-label="追加するオフセット"
          className="w-24"
          value={offset}
          placeholder="+09:00"
          onChange={(e) => setOffset(e.target.value)}
        />
        <Button isLoading={createPunch.isPending} disabled={!punchedAt} onClick={handleSubmit}>
          打刻を追加
        </Button>
      </div>
    </div>
  )
}

function PunchLogCard({ date, locked }: { date: string; locked: boolean }) {
  const { data: punches, isLoading } = usePunches({ from: date, to: date })
  const [isEditing, setIsEditing] = useState(false)
  const editable = isEditing && !locked
  const visiblePunches = editable ? punches : punches?.filter((punch) => punch.status === 'active')
  const editedPunchIds = new Set(punches?.flatMap((punch) => punch.superseded_by_punch_id ?? []))

  return (
    <Card
      title="打刻ログ"
      actions={
        !locked && (
          <Button variant="secondary" onClick={() => setIsEditing((current) => !current)}>
            {isEditing ? '閲覧に戻る' : 'ログを編集'}
          </Button>
        )
      }
    >
      {locked && <MonthLockedNotice />}
      {isLoading ? (
        <LoadingState />
      ) : !punches || punches.length === 0 ? (
        <EmptyState title="この日の打刻ログはありません。" />
      ) : !visiblePunches || visiblePunches.length === 0 ? (
        <EmptyState title="有効な打刻ログはありません。" description="訂正・削除された打刻のみのため、表示できる打刻ログがありません。" />
      ) : (
        <ul className="divide-y divide-border">
          {visiblePunches.map((punch) => (
            <PunchLogRow key={punch.id} punch={punch} isEdited={editedPunchIds.has(punch.id)} isEditing={editable} />
          ))}
        </ul>
      )}
      {editable && <PunchAddForm date={date} />}
    </Card>
  )
}

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能。破壊的操作の
 * 確認は共通の`ConfirmActionDialog`に統一する(`.claude/skills/ui-interaction-patterns`)。
 * 削除理由は必須のため、未入力での確定操作は`onConfirm`内でエラーとして拒否し
 * ダイアログを開いたままにする(`ConfirmActionDialog`はconfirmボタンのdisabled制御を
 * 持たないため)。
 */
function DeleteDayDialog({ day, onDeleted }: { day: AttendanceDay; onDeleted: (punchLogAction: AttendanceDayPunchLogAction) => void }) {
  const [reason, setReason] = useState('')
  const [punchLogAction, setPunchLogAction] = useState<AttendanceDayPunchLogAction>('leave_punches')
  const [validationError, setValidationError] = useState<Error | null>(null)
  const deleteDay = useDeleteAttendanceDay()

  return (
    <ConfirmActionDialog
      triggerLabel="削除"
      triggerVariant="danger"
      title="日次勤怠を削除しますか?"
      description={`${day.work_date} の日次勤怠を削除します。この操作は元に戻せません。承認済みの月次に含まれる場合は削除できません。`}
      confirmLabel="削除する"
      isPending={deleteDay.isPending}
      error={deleteDay.error ?? validationError ?? undefined}
      onOpenChange={(open) => {
        if (open) {
          setReason('')
          setPunchLogAction('leave_punches')
          setValidationError(null)
          deleteDay.reset()
        }
      }}
      onConfirm={async () => {
        if (!reason) {
          setValidationError(new Error('削除理由を入力してください。'))
          throw new Error('削除理由を入力してください。')
        }
        setValidationError(null)
        await deleteDay.mutateAsync({ id: day.id, input: { reason, punch_log_action: punchLogAction } })
        onDeleted(punchLogAction)
      }}
    >
      <Input
        aria-label="削除理由"
        placeholder="削除理由(必須)"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      <NativeSelect
        aria-label="打刻ログの扱い"
        value={punchLogAction}
        onChange={(e) => setPunchLogAction(e.target.value as AttendanceDayPunchLogAction)}
      >
        <option value="leave_punches">打刻ログはそのまま残す</option>
        <option value="delete_punches">有効な打刻ログも削除する</option>
        <option value="recreate_from_punches">打刻ログに合わせて日次勤怠を再作成する</option>
      </NativeSelect>
    </ConfirmActionDialog>
  )
}

type LeaveKind = 'none' | 'paid' | 'special' | 'compensatory'

const LEAVE_WORK_TYPE_PREFIXES: Array<{ kind: LeaveKind; prefix: string }> = [
  { kind: 'paid', prefix: 'paid_leave_' },
  { kind: 'special', prefix: 'special_leave_' },
  { kind: 'compensatory', prefix: 'compensatory_leave_' },
]

/**
 * attendance_days.work_typeのセンチネル値(例: `paid_leave_full`)から休暇の種類と
 * 取得単位を判定する(backend側の PaidLeaveType::toAttendanceWorkType 等と対応)。
 */
function detectLeaveDesignation(workType: string | null | undefined): { kind: LeaveKind; unit: PaidLeaveType | null } {
  for (const { kind, prefix } of LEAVE_WORK_TYPE_PREFIXES) {
    if (workType?.startsWith(prefix)) {
      return { kind, unit: workType.slice(prefix.length) as PaidLeaveType }
    }
  }
  return { kind: 'none', unit: null }
}

function findLeaveRequestForDate<T extends { target_date: string; status: string }>(
  requests: T[] | undefined,
  date: string,
): T | undefined {
  return requests?.find((r) => r.target_date === date && (r.status === 'submitted' || r.status === 'approved'))
}

interface LeaveDesignationLists {
  paidLeaveRequests: PaidLeaveRequest[] | undefined
  specialLeaveRequests: SpecialLeaveRequest[] | undefined
  compensatoryLeaveRequests: CompensatoryLeaveRequest[] | undefined
}

/**
 * 日次勤怠編集画面での「休暇」欄の状態と保存処理をまとめたフック。
 *
 * 実績本体(EditAttendanceDay/CreateAttendanceDay)の保存とは別に、休暇の種類が
 * 選択されている場合はRequest{Paid,Special,Compensatory}Leaveを、休暇なしへ変更した
 * 場合(元々休暇が設定されていた日に限る)はCancel{...}LeaveRequestを呼ぶ
 * (勤怠編集の一部として休暇の申請・取消を行うという設計方針。root CLAUDE.md
 * 「休暇の考え方」参照)。
 */
function useLeaveDesignationController(date: string, initialWorkType: string | null | undefined, lists: LeaveDesignationLists) {
  const initial = detectLeaveDesignation(initialWorkType)
  const existingPaid = findLeaveRequestForDate(lists.paidLeaveRequests, date)
  const existingSpecial = findLeaveRequestForDate(lists.specialLeaveRequests, date)
  const existingCompensatory = findLeaveRequestForDate(lists.compensatoryLeaveRequests, date)
  const existingHours = existingPaid?.hours ?? existingSpecial?.hours ?? existingCompensatory?.hours ?? null

  const [kind, setKind] = useState<LeaveKind>(initial.kind)
  const [unit, setUnit] = useState<PaidLeaveType>(initial.unit ?? 'full')
  const [hours, setHours] = useState(existingHours !== null ? String(existingHours) : '')
  const [specialLeaveTypeId, setSpecialLeaveTypeId] = useState<number | undefined>(existingSpecial?.special_leave_type_id)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(
    existingPaid?.approver?.id ?? existingSpecial?.approver?.id ?? existingCompensatory?.approver?.id,
  )
  const [leaveReason, setLeaveReason] = useState('')

  const createPaidLeave = useCreatePaidLeaveRequest()
  const cancelPaidLeave = useCancelPaidLeaveRequest()
  const createSpecialLeave = useCreateSpecialLeaveRequest()
  const cancelSpecialLeave = useCancelSpecialLeaveRequest()
  const createCompensatoryLeave = useCreateCompensatoryLeaveRequest()
  const cancelCompensatoryLeave = useCancelCompensatoryLeaveRequest()

  const isPending =
    createPaidLeave.isPending ||
    cancelPaidLeave.isPending ||
    createSpecialLeave.isPending ||
    cancelSpecialLeave.isPending ||
    createCompensatoryLeave.isPending ||
    cancelCompensatoryLeave.isPending

  const hasChanged =
    kind !== initial.kind ||
    (kind !== 'none' &&
      (unit !== (initial.unit ?? 'full') ||
        (kind === 'special' && specialLeaveTypeId !== existingSpecial?.special_leave_type_id) ||
        (unit === 'hourly' && Number(hours) !== existingHours)))

  /** 休暇なしへ変更する場合、元の休暇種別に対応するCancel{...}LeaveRequestを呼ぶ。 */
  async function cancelExisting() {
    if (initial.kind === 'paid' && existingPaid) await cancelPaidLeave.mutateAsync(existingPaid.id)
    if (initial.kind === 'special' && existingSpecial) await cancelSpecialLeave.mutateAsync(existingSpecial.id)
    if (initial.kind === 'compensatory' && existingCompensatory) await cancelCompensatoryLeave.mutateAsync(existingCompensatory.id)
  }

  /** 変更があれば取消・申請を行う。変更が無ければ何もしない(既存の申請に触れない)。 */
  async function apply() {
    if (!hasChanged) return

    if (initial.kind !== 'none') {
      await cancelExisting()
    }

    if (kind === 'none') return

    const common = {
      target_date: date,
      leave_type: unit,
      hours: unit === 'hourly' ? Number(hours) : undefined,
      approver_user_id: approverUserId || undefined,
      reason: leaveReason || undefined,
    }

    if (kind === 'paid') await createPaidLeave.mutateAsync(common)
    if (kind === 'special') {
      if (!specialLeaveTypeId) throw new Error('特別休暇の種別を選択してください。')
      await createSpecialLeave.mutateAsync({ ...common, special_leave_type_id: specialLeaveTypeId })
    }
    if (kind === 'compensatory') await createCompensatoryLeave.mutateAsync(common)
  }

  return {
    kind,
    setKind,
    unit,
    setUnit,
    hours,
    setHours,
    specialLeaveTypeId,
    setSpecialLeaveTypeId,
    approverUserId,
    setApproverUserId,
    leaveReason,
    setLeaveReason,
    apply,
    isPending,
  }
}

function DayEditForm({ day, onDone, leaveLists }: { day: AttendanceDay; onDone: () => void; leaveLists: LeaveDesignationLists }) {
  const [actualStartAt, setActualStartAt] = useState(toDatetimeLocal(day.actual_start_at))
  const [actualEndAt, setActualEndAt] = useState(toDatetimeLocal(day.actual_end_at))
  const [offset, setOffset] = useState(
    typeof day.utc_offset_minutes === 'number' ? offsetMinutesToString(day.utc_offset_minutes) : browserOffsetString(),
  )
  const [workType, setWorkType] = useState(day.work_type ?? '')
  const [workLocationType, setWorkLocationType] = useState<WorkLocationType | ''>(day.work_location_type ?? '')
  const [note, setNote] = useState(day.note ?? '')
  const [reason, setReason] = useState('')
  const { rows, addRow, updateRow, removeRow } = useEditableRows<BreakRowData>(
    day.breaks.map((b) => ({ start: toDatetimeLocal(b.break_start_at), end: toDatetimeLocal(b.break_end_at) })),
  )
  const {
    rows: leaveSegmentRows,
    addRow: addLeaveSegmentRow,
    updateRow: updateLeaveSegmentRow,
    removeRow: removeLeaveSegmentRow,
  } = useEditableRows<LeaveSegmentRowData>(
    (day.leave_segments ?? []).map((segment) => ({
      start: toDatetimeLocal(segment.start_at),
      end: toDatetimeLocal(segment.end_at),
      note: segment.note ?? '',
    })),
  )
  const updateDay = useUpdateAttendanceDay()
  const breakWarning = useBreakShortfallWarning(actualStartAt, actualEndAt, rows)
  const leaveController = useLeaveDesignationController(day.work_date, day.work_type, leaveLists)
  const [leaveError, setLeaveError] = useState<Error | null>(null)
  useUnsavedChangesGuard(true)

  const handleSave = () => {
    setLeaveError(null)
    updateDay.mutate(
      {
        id: day.id,
        input: {
          actual_start_at: combineDatetimeLocalWithOffset(actualStartAt, offset),
          actual_end_at: combineDatetimeLocalWithOffset(actualEndAt, offset),
          breaks: buildBreaksPayload(rows, offset),
          work_type: leaveController.kind === 'none' ? workType || null : null,
          work_location_type: workLocationType || null,
          note: note || null,
          leave_segments: buildLeaveSegmentsPayload(leaveSegmentRows, offset),
          reason,
        },
      },
      {
        onSuccess: async () => {
          try {
            await leaveController.apply()
            onDone()
          } catch (error) {
            setLeaveError(error as Error)
          }
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {updateDay.error && <ErrorMessage error={updateDay.error} />}
      {leaveError && <ErrorMessage error={leaveError} />}

      <div className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        出勤
        <DateTimePicker aria-label="出勤" value={actualStartAt || undefined} onChange={(value) => setActualStartAt(value ?? '')} />
      </div>
      <div className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        退勤
        <DateTimePicker aria-label="退勤" value={actualEndAt || undefined} onChange={(value) => setActualEndAt(value ?? '')} />
      </div>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        現地時刻オフセット(海外出張時などに変更)
        <Input value={offset} placeholder="+09:00" pattern="^[+-]\d{2}:\d{2}$" onChange={(e) => setOffset(e.target.value)} />
      </label>

      {leaveController.kind === 'none' && (
        <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
          作業内容
          <Input value={workType} onChange={(e) => setWorkType(e.target.value)} />
        </label>
      )}
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        勤務形態区分
        <NativeSelect
          value={workLocationType}
          onChange={(e) => setWorkLocationType(e.target.value as WorkLocationType | '')}
        >
          <option value="">未設定</option>
          {WORK_LOCATION_TYPE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        備考
        <Input value={note} onChange={(e) => setNote(e.target.value)} />
      </label>

      <BreakRowsEditor rows={rows} onAdd={() => addRow({ start: '', end: '' })} onUpdate={updateRow} onRemove={removeRow} />

      {breakWarning && <p className="text-sm text-warning">{breakWarning}</p>}

      <LeaveSegmentsEditor
        rows={leaveSegmentRows}
        onAdd={() => addLeaveSegmentRow({ start: '', end: '', note: '' })}
        onUpdate={updateLeaveSegmentRow}
        onRemove={removeLeaveSegmentRow}
      />

      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        修正理由(必須)
        <Input value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <div className="flex gap-2 pt-1">
        <Button variant="secondary" onClick={onDone}>
          キャンセル
        </Button>
        <Button isLoading={updateDay.isPending || leaveController.isPending} disabled={!reason} onClick={handleSave}>
          保存する
        </Button>
      </div>
    </div>
  )
}

/** UC-A016: 出勤日を新規作成する。打刻の有無にかかわらず、月が締められるまではいつでも作成できる。 */
function DayCreateForm({ date, leaveLists }: { date: string; leaveLists: LeaveDesignationLists }) {
  const { user } = useAuth()
  const [actualStartAt, setActualStartAt] = useState('')
  const [actualEndAt, setActualEndAt] = useState('')
  const [offset, setOffset] = useState(browserOffsetString())
  const [workType, setWorkType] = useState('')
  const [workLocationType, setWorkLocationType] = useState<WorkLocationType | ''>('')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const { rows, addRow, updateRow, removeRow, reset: resetRows } = useEditableRows<BreakRowData>([])
  const {
    rows: leaveSegmentRows,
    addRow: addLeaveSegmentRow,
    updateRow: updateLeaveSegmentRow,
    removeRow: removeLeaveSegmentRow,
  } = useEditableRows<LeaveSegmentRowData>([])
  const createDay = useCreateAttendanceDay()
  const { data: defaults } = useAttendanceDayDefaults(user?.id, date)
  const appliedDefaultsDateRef = useRef<string | null>(null)

  // 日付が変わったら前日の入力状態を破棄する。同じ週の前日・翌日へ遷移した場合も
  // コンポーネントの再マウント有無に依存せず、遷移先の日付の初期値を表示する。
  useEffect(() => {
    appliedDefaultsDateRef.current = null
    setActualStartAt('')
    setActualEndAt('')
    setOffset(browserOffsetString())
    setWorkType('')
    setWorkLocationType('')
    setNote('')
    setReason('')
    resetRows([])
  }, [date, resetRows])

  // 打刻→勤務予定(休憩を含む)→システムの初期設定の優先順位で提案された値を
  // 日付ごとに一度だけ反映する(同じ日付のリフェッチでは入力済みの値を上書きしない)。
  useEffect(() => {
    if (!defaults || appliedDefaultsDateRef.current === date) return
    appliedDefaultsDateRef.current = date
    if (defaults.source === 'none') return

    const referenceOffset = isoToOffsetString(defaults.actual_start_at ?? defaults.breaks[0]?.start)
    setOffset(referenceOffset)
    setActualStartAt(toDatetimeLocal(defaults.actual_start_at))
    setActualEndAt(toDatetimeLocal(defaults.actual_end_at))
    resetRows(defaults.breaks.map((b) => ({ start: toDatetimeLocal(b.start), end: toDatetimeLocal(b.end) })))
  }, [date, defaults, resetRows])

  const breakWarning = useBreakShortfallWarning(actualStartAt, actualEndAt, rows)
  const leaveController = useLeaveDesignationController(date, null, leaveLists)
  const [leaveError, setLeaveError] = useState<Error | null>(null)
  const isDirty = Boolean(
    actualStartAt || actualEndAt || note || workType || reason || rows.some((r) => r.start || r.end) || leaveController.kind !== 'none',
  )
  useUnsavedChangesGuard(isDirty)

  const handleCreate = () => {
    if (!user) return
    setLeaveError(null)
    createDay.mutate(
      {
        user_id: user.id,
        work_date: date,
        actual_start_at: combineDatetimeLocalWithOffset(actualStartAt, offset),
        actual_end_at: combineDatetimeLocalWithOffset(actualEndAt, offset),
        breaks: buildBreaksPayload(rows, offset),
        work_type: leaveController.kind === 'none' ? workType || null : null,
        work_location_type: workLocationType || null,
        note: note || null,
        leave_segments: buildLeaveSegmentsPayload(leaveSegmentRows, offset),
        reason,
      },
      {
        onSuccess: async () => {
          try {
            await leaveController.apply()
          } catch (error) {
            setLeaveError(error as Error)
          }
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">この日の勤怠記録はまだありません。実績を入力して作成できます。</p>
      {createDay.error && <ErrorMessage error={createDay.error} />}
      {leaveError && <ErrorMessage error={leaveError} />}
      {createDay.isSuccess && <p className="text-sm text-success">実績を作成しました。</p>}
      {defaults && DAY_DEFAULTS_SOURCE_LABEL[defaults.source] && (
        <p className="text-sm text-info">{DAY_DEFAULTS_SOURCE_LABEL[defaults.source]}</p>
      )}

      <div className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        出勤
        <DateTimePicker aria-label="出勤" value={actualStartAt || undefined} onChange={(value) => setActualStartAt(value ?? '')} />
      </div>
      <div className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        退勤
        <DateTimePicker aria-label="退勤" value={actualEndAt || undefined} onChange={(value) => setActualEndAt(value ?? '')} />
      </div>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        現地時刻オフセット(海外出張時などに変更)
        <Input value={offset} placeholder="+09:00" pattern="^[+-]\d{2}:\d{2}$" onChange={(e) => setOffset(e.target.value)} />
      </label>

      {leaveController.kind === 'none' && (
        <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
          作業内容
          <Input value={workType} onChange={(e) => setWorkType(e.target.value)} />
        </label>
      )}
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        勤務形態区分
        <NativeSelect
          value={workLocationType}
          onChange={(e) => setWorkLocationType(e.target.value as WorkLocationType | '')}
        >
          <option value="">未設定</option>
          {WORK_LOCATION_TYPE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        備考
        <Input value={note} onChange={(e) => setNote(e.target.value)} />
      </label>

      <BreakRowsEditor rows={rows} onAdd={() => addRow({ start: '', end: '' })} onUpdate={updateRow} onRemove={removeRow} />

      {breakWarning && <p className="text-sm text-warning">{breakWarning}</p>}

      <LeaveSegmentsEditor
        rows={leaveSegmentRows}
        onAdd={() => addLeaveSegmentRow({ start: '', end: '', note: '' })}
        onUpdate={updateLeaveSegmentRow}
        onRemove={removeLeaveSegmentRow}
      />

      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        作成理由(必須)
        <Input value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <Button
        className="self-start"
        isLoading={createDay.isPending || leaveController.isPending}
        disabled={!reason}
        onClick={handleCreate}
      >
        作成する
      </Button>
    </div>
  )
}

interface AdjustmentFields {
  prescribed_work_minutes: string
  statutory_within_overtime_minutes: string
  statutory_excess_overtime_minutes: string
  legal_holiday_work_minutes: string
  prescribed_holiday_work_minutes: string
  payroll_work_minutes: string
  late_night_prescribed_work_minutes: string
  late_night_statutory_within_overtime_minutes: string
  late_night_statutory_excess_overtime_minutes: string
  late_night_legal_holiday_work_minutes: string
  late_night_prescribed_holiday_work_minutes: string
}

function adjustmentFieldsFrom(day: AttendanceDay): AdjustmentFields {
  const c = day.calculation
  return {
    prescribed_work_minutes: String(c?.prescribed_work_minutes ?? 0),
    statutory_within_overtime_minutes: String(c?.statutory_within_overtime_minutes ?? 0),
    statutory_excess_overtime_minutes: String(c?.statutory_excess_overtime_minutes ?? 0),
    legal_holiday_work_minutes: String(c?.legal_holiday_work_minutes ?? 0),
    prescribed_holiday_work_minutes: String(c?.prescribed_holiday_work_minutes ?? 0),
    payroll_work_minutes: String(c?.payroll_work_minutes ?? 0),
    late_night_prescribed_work_minutes: String(c?.late_night_prescribed_work_minutes ?? 0),
    late_night_statutory_within_overtime_minutes: String(c?.late_night_statutory_within_overtime_minutes ?? 0),
    late_night_statutory_excess_overtime_minutes: String(c?.late_night_statutory_excess_overtime_minutes ?? 0),
    late_night_legal_holiday_work_minutes: String(c?.late_night_legal_holiday_work_minutes ?? 0),
    late_night_prescribed_holiday_work_minutes: String(c?.late_night_prescribed_holiday_work_minutes ?? 0),
  }
}

/**
 * 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正するフォーム。
 * 実績(出勤・退勤・休憩)が再編集され再計算されると、この補正は解除される。
 */
function CalculationAdjustForm({ day, onDone }: { day: AttendanceDay; onDone: () => void }) {
  const [fields, setFields] = useState<AdjustmentFields>(adjustmentFieldsFrom(day))
  const [reason, setReason] = useState('')
  const adjustCalculation = useAdjustAttendanceDailyCalculation()
  useUnsavedChangesGuard(true)

  const updateField = (key: keyof AdjustmentFields, value: string) => setFields((prev) => ({ ...prev, [key]: value }))

  const handleSave = () => {
    adjustCalculation.mutate(
      {
        id: day.id,
        input: {
          prescribed_work_minutes: Number(fields.prescribed_work_minutes),
          statutory_within_overtime_minutes: Number(fields.statutory_within_overtime_minutes),
          statutory_excess_overtime_minutes: Number(fields.statutory_excess_overtime_minutes),
          legal_holiday_work_minutes: Number(fields.legal_holiday_work_minutes),
          prescribed_holiday_work_minutes: Number(fields.prescribed_holiday_work_minutes),
          payroll_work_minutes: Number(fields.payroll_work_minutes),
          late_night_prescribed_work_minutes: Number(fields.late_night_prescribed_work_minutes),
          late_night_statutory_within_overtime_minutes: Number(fields.late_night_statutory_within_overtime_minutes),
          late_night_statutory_excess_overtime_minutes: Number(fields.late_night_statutory_excess_overtime_minutes),
          late_night_legal_holiday_work_minutes: Number(fields.late_night_legal_holiday_work_minutes),
          late_night_prescribed_holiday_work_minutes: Number(fields.late_night_prescribed_holiday_work_minutes),
          reason,
        },
      },
      { onSuccess: () => onDone() },
    )
  }

  const fieldLabels: Array<{ key: keyof AdjustmentFields; label: string }> = [
    { key: 'prescribed_work_minutes', label: '所定労働時間(分)' },
    { key: 'statutory_within_overtime_minutes', label: '法定内残業時間(分)' },
    { key: 'statutory_excess_overtime_minutes', label: '法定外残業時間(分)' },
    { key: 'legal_holiday_work_minutes', label: '法定休日労働時間(分)' },
    { key: 'prescribed_holiday_work_minutes', label: '所定休日労働時間(分)' },
    { key: 'payroll_work_minutes', label: '給与計算上の労働時間(分)(みなし時間等)' },
    { key: 'late_night_prescribed_work_minutes', label: 'うち深夜所定労働時間(分)' },
    { key: 'late_night_statutory_within_overtime_minutes', label: 'うち深夜法定内残業時間(分)' },
    { key: 'late_night_statutory_excess_overtime_minutes', label: 'うち深夜法定外残業時間(分)' },
    { key: 'late_night_legal_holiday_work_minutes', label: 'うち深夜法定休日労働時間(分)' },
    { key: 'late_night_prescribed_holiday_work_minutes', label: 'うち深夜所定休日労働時間(分)' },
  ]

  return (
    <div className="flex flex-col gap-3 rounded-md border border-border p-3">
      {adjustCalculation.error && <ErrorMessage error={adjustCalculation.error} />}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {fieldLabels.map(({ key, label }) => (
          <label key={key} className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
            {label}
            <Input
              type="number"
              min={0}
              value={fields[key]}
              onChange={(e) => updateField(key, e.target.value)}
            />
          </label>
        ))}
      </div>

      <label className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        補正理由(必須)
        <Input value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <div className="flex gap-2">
        <Button variant="secondary" onClick={onDone}>
          キャンセル
        </Button>
        <Button isLoading={adjustCalculation.isPending} disabled={!reason} onClick={handleSave}>
          補正を保存する
        </Button>
      </div>
    </div>
  )
}

/**
 * 休日(法定休日/所定休日)の日に、別の日へ振替える申請を行うダイアログ。
 * 振替先日はカレンダー上での入力を制限せず(サーバー側のバリデーションに委ねる)、
 * 承認要否(`shift_swap_requires_approval`)に応じて承認者欄の必須表示・説明文言を切り替える。
 */
function ShiftSwapRequestDialog({
  targetDate,
  targetIsHoliday,
  open,
  onOpenChange,
}: {
  targetDate: string
  targetIsHoliday: boolean
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { systemSettings } = useAppSettings()
  const approvalRequired = systemSettings.shift_swap_requires_approval

  const [substituteDate, setSubstituteDate] = useState<string | undefined>(undefined)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const [reason, setReason] = useState('')
  const [completed, setCompleted] = useState(false)
  const createRequest = useCreateShiftSwapRequest()

  const canSubmit = Boolean(substituteDate) && (!approvalRequired || Boolean(approverUserId))

  const handleSubmit = () => {
    if (!substituteDate) return
    if (approvalRequired && !approverUserId) return

    createRequest.mutate(
      {
        target_date: targetDate,
        substitute_date: substituteDate,
        approver_user_id: approverUserId || undefined,
        reason: reason || undefined,
      },
      {
        onSuccess: () => {
          onOpenChange(false)
          setCompleted(true)
        },
      },
    )
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <Dialog
        open={open}
        onOpenChange={(nextOpen) => {
          onOpenChange(nextOpen)
          if (nextOpen) {
            setSubstituteDate(undefined)
            setApproverUserId(undefined)
            setReason('')
            setCompleted(false)
            createRequest.reset()
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>振替休日を申請する</DialogTitle>
            <DialogDescription>
              {targetIsHoliday
                ? `${targetDate} に出勤する代わりに、別の日を休みにします。`
                : `${targetDate} を休みにする代わりに、別の日に出勤します。`}
              {approvalRequired ? '承認後にシフトが入れ替わります。' : '送信するとすぐにシフトが入れ替わります。'}
            </DialogDescription>
          </DialogHeader>

          {createRequest.error && <ErrorMessage error={createRequest.error} />}

          <FormField
            label={targetIsHoliday ? '振替先日(休みになる日)' : '振替先日(出勤する日)'}
            htmlFor="shift-swap-substitute-date"
            required
          >
            <DatePicker id="shift-swap-substitute-date" value={substituteDate} onChange={setSubstituteDate} />
          </FormField>

          <FormField
            label={approvalRequired ? '承認者' : '承認者(任意)'}
            htmlFor="shift-swap-approver"
            required={approvalRequired}
          >
            <UserPicker id="shift-swap-approver" value={approverUserId} onChange={setApproverUserId} />
            {!approvalRequired && (
              <p className="mt-1 text-xs text-muted-foreground">
                現在の設定では振替休日申請に承認は不要です。申請すると同時に確定します。承認者の指定は任意です。
              </p>
            )}
          </FormField>

          <FormField label="理由(任意)" htmlFor="shift-swap-reason">
            <Input id="shift-swap-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
          </FormField>

          <DialogFooter>
            <Button variant="secondary" onClick={() => onOpenChange(false)}>
              キャンセル
            </Button>
            <Button isLoading={createRequest.isPending} disabled={!canSubmit} onClick={handleSubmit}>
              申請する
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      {completed && <p className="text-sm text-success">振替休日を申請しました。</p>}
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
  const queryClient = useQueryClient()
  const [isEditing, setIsEditing] = useState(false)
  const [isAdjustingCalculation, setIsAdjustingCalculation] = useState(false)
  const [isShiftSwapOpen, setIsShiftSwapOpen] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<ApprovedLeaveTarget | null>(null)

  const monday = date ? formatDate(mondayOf(new Date(`${date}T00:00:00`))) : ''
  const { data: weekDays, isLoading, error } = useWeek(monday)
  const { user } = useAuth()
  const { data: scheduleDays } = useShiftAssignments(user?.id ?? '', monday, addDays(monday, 6))
  const monthLocked = useMonthLocked(date ?? '')
  const { data: paidLeaveRequests } = useMyPaidLeaveRequests()
  const { data: specialLeaveRequests } = useMySpecialLeaveRequests()
  const { data: compensatoryLeaveRequests } = useMyCompensatoryLeaveRequests()

  if (!date) return null
  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="日次勤怠の取得に失敗しました。" />

  const day = weekDays?.find((d) => d.work_date === date)
  const schedule = scheduleDays?.find((entry) => entry.work_date === date)
  const locked = monthLocked || day?.is_locked === true
  const statusMeta = day ? attendanceDayDisplayLabel(day) : null
  const today = formatDate(new Date())
  const isHoliday = schedule?.is_working_day === false
    || day?.day_classification === 'prescribed_holiday'
    || day?.day_classification === 'legal_holiday'
  const holidayLabel = schedule?.is_legal_holiday
    ? '法定休日'
    : schedule?.is_company_holiday || schedule?.is_working_day === false
      ? '所定休日'
      : null
  const absenceDays = day?.calculation && day.calculation.prescribed_work_minutes > 0 && (day.calculation.absence_minutes ?? 0) >= day.calculation.prescribed_work_minutes
    ? 1
    : 0
  const approvedPaidLeave = paidLeaveRequests?.find((r) => r.target_date === date && r.status === 'approved')
  const approvedSpecialLeave = specialLeaveRequests?.find((r) => r.target_date === date && r.status === 'approved')
  const approvedCompensatoryLeave = compensatoryLeaveRequests?.find((r) => r.target_date === date && r.status === 'approved')
  const hasApprovedLeaveToCancel = !!approvedPaidLeave || !!approvedSpecialLeave || !!approvedCompensatoryLeave
  const leaveLists: LeaveDesignationLists = { paidLeaveRequests, specialLeaveRequests, compensatoryLeaveRequests }

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="日次勤怠"
        actions={
          <span className="flex items-center gap-1.5">
            {!!day?.calculation?.absence_minutes && <Badge tone="warning">欠勤あり</Badge>}
            {holidayLabel && <Badge tone={schedule?.is_legal_holiday ? 'danger' : 'warning'}>{holidayLabel}</Badge>}
            {statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
          </span>
        }
        navigation={
          <div className="flex gap-2">
            <Button asChild variant="secondary" size="icon" title="前日" aria-label="前日">
              <Link to={`/attendance/days/${addDays(date, -1)}`}>
                <ChevronLeft aria-hidden="true" />
              </Link>
            </Button>
            {date === today ? (
              <Button variant="secondary" disabled>
                今日
              </Button>
            ) : (
              <Button asChild variant="secondary">
                <Link to={`/attendance/days/${today}`}>今日</Link>
              </Button>
            )}
            <Button asChild variant="secondary" title="この週で見る">
              <Link to={`/attendance/week?start=${monday}`}>
                <CalendarRange aria-hidden="true" />
                週次
              </Link>
            </Button>
            <Button asChild variant="secondary" size="icon" title="翌日" aria-label="翌日">
              <Link to={`/attendance/days/${addDays(date, 1)}`}>
                <ChevronRight aria-hidden="true" />
              </Link>
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="secondary" size="icon" aria-label="この日の操作">
                  <MoreVertical aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => setIsShiftSwapOpen(true)}>
                  {isHoliday ? '振替休日を申請する' : 'この日を振替休日にする'}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link to={`/paid-leave?date=${date}`}>有給休暇を申請する</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link to={`/special-leave?date=${date}`}>特別休暇を申請する</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link to={`/compensatory-leave?date=${date}`}>代休を申請する</Link>
                </DropdownMenuItem>
                {hasApprovedLeaveToCancel && <DropdownMenuSeparator />}
                {approvedPaidLeave && (
                  <DropdownMenuItem
                    onSelect={() => setCancelTarget({ kind: 'paid', id: approvedPaidLeave.id, label: '有給休暇' })}
                  >
                    有給休暇の承認を取り消す
                  </DropdownMenuItem>
                )}
                {approvedSpecialLeave && (
                  <DropdownMenuItem
                    onSelect={() => setCancelTarget({ kind: 'special', id: approvedSpecialLeave.id, label: '特別休暇' })}
                  >
                    特別休暇の承認を取り消す
                  </DropdownMenuItem>
                )}
                {approvedCompensatoryLeave && (
                  <DropdownMenuItem
                    onSelect={() =>
                      setCancelTarget({ kind: 'compensatory', id: approvedCompensatoryLeave.id, label: '代休' })
                    }
                  >
                    代休の承認を取り消す
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        }
      >
        <p className="mb-3 text-sm text-muted-foreground">{date}({weekdayLabel(date)})</p>

        <ShiftSwapRequestDialog
          targetDate={date}
          targetIsHoliday={isHoliday}
          open={isShiftSwapOpen}
          onOpenChange={setIsShiftSwapOpen}
        />

        <CancelApprovedLeaveDialog
          target={cancelTarget}
          onOpenChange={(open) => !open && setCancelTarget(null)}
          onCancelled={() => {
            setCancelTarget(null)
            void queryClient.invalidateQueries({ queryKey: ['attendance'] })
          }}
        />

        {day && !isEditing && (
          <div className="flex flex-col gap-4 border-t border-border pt-4">
            {day.calculation && !isAdjustingCalculation && (
              <div className="flex flex-col gap-2">
                <AttendanceCalculationSummary
                  title="この日の集計"
                  totals={day.calculation}
                  absenceDays={day.calculation.absence_minutes ? absenceDays : undefined}
                  specialLeaveBreakdown={specialLeaveTypeBreakdown([day])}
                />

                <div className="flex items-center gap-2">
                  {day.calculation.is_manually_adjusted && <Badge tone="info">手動補正済み</Badge>}
                  {!locked && (
                    <Button variant="secondary" onClick={() => setIsAdjustingCalculation(true)}>
                      集計値を修正
                    </Button>
                  )}
                </div>
              </div>
            )}

            {day.calculation && isAdjustingCalculation && (
              <CalculationAdjustForm day={day} onDone={() => setIsAdjustingCalculation(false)} />
            )}

            {day.monthly_overtime && (
              <p className="text-xs text-muted-foreground">
                今月の法定外残業累計(参考): <Duration minutes={day.monthly_overtime.cumulative_statutory_excess_overtime_minutes} />
                (うち月60時間超残業: <Duration minutes={day.monthly_overtime.statutory_excess_overtime_over_60h_minutes} />)
              </p>
            )}
          </div>
        )}
      </Card>

      <Card title="日別の内訳">
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

            {(day.work_type || day.work_location_type || day.note) && (
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                {day.work_type && (
                  <>
                    <dt className="font-medium text-muted-foreground">作業内容</dt>
                    <dd className="text-foreground">{day.work_type}</dd>
                  </>
                )}
                {day.work_location_type && (
                  <>
                    <dt className="font-medium text-muted-foreground">勤務形態区分</dt>
                    <dd className="text-foreground">{workLocationTypeLabel(day.work_location_type)}</dd>
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

            {locked ? (
              <div className="border-t border-border pt-4">
                <MonthLockedNotice />
              </div>
            ) : (
              <div className="flex gap-2 border-t border-border pt-4">
                <Button
                  onClick={() => {
                    setIsAdjustingCalculation(false)
                    setIsEditing(true)
                  }}
                >
                  編集
                </Button>
                <DeleteDayDialog
                  day={day}
                  onDeleted={(punchLogAction) => {
                    if (punchLogAction !== 'recreate_from_punches') navigate(-1)
                  }}
                />
              </div>
            )}
          </div>
        )}

        {day && isEditing && <DayEditForm day={day} onDone={() => setIsEditing(false)} leaveLists={leaveLists} />}

        {!day && locked && (
          <div className="flex flex-col gap-2">
            <MonthLockedNotice />
          </div>
        )}

        {!day && !locked && <DayCreateForm date={date} leaveLists={leaveLists} />}
      </Card>

      <PunchLogCard date={date} locked={locked} />
    </div>
  )
}
