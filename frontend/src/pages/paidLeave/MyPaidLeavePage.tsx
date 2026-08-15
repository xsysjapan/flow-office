import { useState } from 'react'
import { X } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { DateRangePicker, type DateRangeValue } from '../../components/DateRangePicker/DateRangePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type { PaidLeaveType } from '../../api/types'
import { useAppSettings } from '../../contexts/useAppSettings'
import {
  useCancelPaidLeaveRequest,
  useCreatePaidLeaveRequest,
  useMyPaidLeaveGrants,
  useMyPaidLeaveRequests,
} from '../../hooks/usePaidLeave'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../../utils/statusLabels'
import { addDays } from '../../utils/weekDates'

const LEAVE_TYPE_OPTIONS: Array<{ value: PaidLeaveType; label: string }> = [
  { value: 'full', label: '全休' },
  { value: 'am_half', label: '午前半休' },
  { value: 'pm_half', label: '午後半休' },
  { value: 'hourly', label: '時間休' },
]

type TargetDateMode = 'dates' | 'range'

const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/

/** 期間指定(from〜to)を暦日1日ずつの"YYYY-MM-DD"配列に列挙する(両端含む)。 */
function enumerateRange(range: DateRangeValue | undefined): string[] {
  if (!range?.from) return []
  const to = range.to ?? range.from
  const dates: string[] = []
  let cursor = range.from
  while (cursor <= to) {
    dates.push(cursor)
    cursor = addDays(cursor, 1)
  }
  return dates
}

function PaidLeaveRequestForm() {
  const { systemSettings } = useAppSettings()
  const approvalRequired = systemSettings.paid_leave_requires_approval

  const [searchParams] = useSearchParams()
  const dateParam = searchParams.get('date')
  const initialDate = dateParam && ISO_DATE_PATTERN.test(dateParam) ? dateParam : undefined

  const [targetMode, setTargetMode] = useState<TargetDateMode>('dates')
  const [selectedDates, setSelectedDates] = useState<string[]>(() => (initialDate ? [initialDate] : []))
  const [range, setRange] = useState<DateRangeValue | undefined>(undefined)
  const [leaveType, setLeaveType] = useState<PaidLeaveType>('full')
  const [hours, setHours] = useState('')
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const [reason, setReason] = useState('')
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const createRequest = useCreatePaidLeaveRequest()

  const targetDates = targetMode === 'range' ? enumerateRange(range) : selectedDates
  const isMultiDay = targetDates.length > 1
  const effectiveLeaveType: PaidLeaveType = isMultiDay ? 'full' : leaveType

  const canSubmit =
    targetDates.length > 0 &&
    (!approvalRequired || approverUserId) &&
    (isMultiDay || effectiveLeaveType !== 'hourly' || Number(hours) > 0)

  const disabledReason =
    targetDates.length === 0
      ? '対象日を1つ以上選択してください。'
      : approvalRequired && !approverUserId
        ? '承認者を選択してください。'
        : !isMultiDay && effectiveLeaveType === 'hourly' && !(Number(hours) > 0)
          ? '取得時間を入力してください。'
          : null

  /** 日付を選ぶと即座に対象日一覧へ加える(重複は無視し、日付順に並べる)。 */
  const addDate = (value: string | undefined) => {
    if (!value) return
    setSelectedDates((prev) => (prev.includes(value) ? prev : [...prev, value].sort()))
  }

  const removeDate = (value: string) => setSelectedDates((prev) => prev.filter((d) => d !== value))

  const resetForm = () => {
    setTargetMode('dates')
    setSelectedDates([])
    setRange(undefined)
    setHours('')
    setApproverUserId(undefined)
    setReason('')
  }

  const handleSubmit = async () => {
    if (approvalRequired && !approverUserId) return
    if (targetDates.length === 0) return

    setSuccessMessage(null)

    // 複数日をまとめて申請する場合、承認者が1回の操作でまとめて承認できるよう
    // 同じrequest_group_idを全日分に渡す(単日申請では付与しない)。
    const requestGroupId = isMultiDay ? crypto.randomUUID() : undefined

    let successCount = 0
    for (const targetDate of targetDates) {
      try {
        // eslint-disable-next-line no-await-in-loop
        await createRequest.mutateAsync({
          target_date: targetDate,
          leave_type: effectiveLeaveType,
          hours: effectiveLeaveType === 'hourly' ? Number(hours) : undefined,
          approver_user_id: approverUserId || undefined,
          reason: reason || undefined,
          request_group_id: requestGroupId,
        })
        successCount++
      } catch {
        break
      }
    }

    if (successCount === targetDates.length) {
      resetForm()
      setSuccessMessage(`${successCount}日分の有給申請を送信しました。`)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      {createRequest.error && <ErrorMessage error={createRequest.error} />}
      {successMessage && <p className="text-sm text-success">{successMessage}</p>}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象日の指定方法" htmlFor="paid-leave-target-mode">
          <NativeSelect
            id="paid-leave-target-mode"
            value={targetMode}
            onChange={(e) => setTargetMode(e.target.value as TargetDateMode)}
          >
            <option value="dates">日付を指定</option>
            <option value="range">期間を指定</option>
          </NativeSelect>
        </FormField>

        {targetMode === 'dates' ? (
          <FormField label="対象日" htmlFor="paid-leave-target-date" required>
            <div className="flex flex-col gap-2">
              <DatePicker id="paid-leave-target-date" value={undefined} onChange={addDate} />
              {selectedDates.length > 0 && (
                <ul className="flex flex-wrap gap-1.5">
                  {selectedDates.map((selectedDate) => (
                    <li
                      key={selectedDate}
                      className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/30 py-1 pr-1 pl-2 text-sm text-foreground"
                    >
                      {selectedDate}
                      <button
                        type="button"
                        aria-label={`${selectedDate}を削除`}
                        className="rounded-sm p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        onClick={() => removeDate(selectedDate)}
                      >
                        <X className="size-3.5" aria-hidden="true" />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </FormField>
        ) : (
          <FormField label="対象日(期間)" htmlFor="paid-leave-target-range" required>
            <DateRangePicker id="paid-leave-target-range" value={range} onChange={setRange} />
          </FormField>
        )}

        {isMultiDay ? (
          <p className="text-xs text-muted-foreground sm:col-span-2">
            複数日をまとめて申請する場合、取得単位は全休固定になります。
          </p>
        ) : (
          <FormField label="取得単位" htmlFor="paid-leave-type" required>
            <NativeSelect
              id="paid-leave-type"
              value={leaveType}
              onChange={(e) => setLeaveType(e.target.value as PaidLeaveType)}
            >
              {LEAVE_TYPE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        )}

        {!isMultiDay && leaveType === 'hourly' && (
          <FormField label="取得時間" htmlFor="paid-leave-hours" required>
            <Input
              id="paid-leave-hours"
              type="number"
              min="0.5"
              step="0.5"
              value={hours}
              onChange={(e) => setHours(e.target.value)}
            />
          </FormField>
        )}

        <FormField label={approvalRequired ? '承認者' : '承認者(任意)'} htmlFor="paid-leave-approver" required={approvalRequired}>
          <UserPicker id="paid-leave-approver" value={approverUserId} onChange={setApproverUserId} />
          {!approvalRequired && (
            <p className="mt-1 text-xs text-muted-foreground">
              現在の設定では有給申請に承認は不要です。申請すると同時に確定します。承認者の指定は任意です。
            </p>
          )}
        </FormField>

        <FormField label="理由(任意)" htmlFor="paid-leave-reason">
          <Input id="paid-leave-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>

      <div className="flex flex-col items-start gap-1">
        <Button isLoading={createRequest.isPending} disabled={!canSubmit} onClick={handleSubmit}>
          申請する
        </Button>
        {!canSubmit && disabledReason && <p className="text-xs text-muted-foreground">{disabledReason}</p>}
      </div>
    </div>
  )
}

function MyPaidLeaveRequestList() {
  const { data, isLoading, error } = useMyPaidLeaveRequests()
  const cancelRequest = useCancelPaidLeaveRequest()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="有給申請の取得に失敗しました。" />

  const requests = data ?? []

  if (requests.length === 0) {
    return <EmptyState title="有給申請はまだありません。" description="有給を申請すると、ここに一覧が表示されます。" />
  }

  return (
    <ul className="divide-y divide-border">
      {requests.map((req) => {
        const { label, tone } = paidLeaveRequestStatusLabel(req.status)
        return (
          <li key={req.id} className="flex items-center justify-between gap-3 py-3">
            <div className="flex items-center gap-4 text-sm">
              <span className="text-foreground">{req.target_date}</span>
              <span className="text-muted-foreground">{paidLeaveTypeLabel(req.leave_type)}</span>
              <span className="text-muted-foreground">{req.requested_days}日</span>
              <Badge tone={tone}>{label}</Badge>
            </div>
            {req.status === 'submitted' && (
              // 取消は元に戻せない操作(SKILL.md §2.12)のため、ConfirmActionDialogで結果を確認させる
              // (WorkflowRequestDetailPageの取消確認と同じ扱い)。
              <ConfirmActionDialog
                triggerLabel="取消"
                triggerVariant="secondary"
                title={`${req.target_date}の有給申請を取り消しますか?`}
                description="この操作は元に戻せません。申請は取消状態になります。"
                confirmLabel="取消する"
                isPending={cancelRequest.isPending && cancelRequest.variables === req.id}
                error={cancelRequest.variables === req.id ? cancelRequest.error : undefined}
                onConfirm={() => cancelRequest.mutateAsync(req.id)}
              />
            )}
          </li>
        )
      })}
    </ul>
  )
}

/**
 * UC-P001: 自分の有給付与状況を確認する。
 * UC-P003: 有給を申請する。
 */
export function MyPaidLeavePage() {
  const { data, isLoading, error } = useMyPaidLeaveGrants()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="有給情報の取得に失敗しました。" />

  const grants = data ?? []
  const totalRemaining = grants.reduce((sum, grant) => sum + grant.remaining_days, 0)

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="自分の有給"
        actions={
          <Button asChild variant="secondary">
            <Link to="/paid-leave/history">履歴を見る</Link>
          </Button>
        }
      >
        <p className="mb-4 text-sm text-foreground">
          残り<strong className="mx-1 text-lg font-semibold">{totalRemaining}</strong>日
        </p>

        {grants.length === 0 ? (
          <EmptyState title="有給の付与はまだありません。" description="有給が付与されると、ここに一覧が表示されます。" />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>付与日</TableHead>
                <TableHead>失効日</TableHead>
                <TableHead>付与日数</TableHead>
                <TableHead>使用日数</TableHead>
                <TableHead>残日数</TableHead>
                <TableHead>付与理由</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {grants.map((grant) => (
                <TableRow key={grant.id}>
                  <TableCell>{grant.granted_on}</TableCell>
                  <TableCell>{grant.expires_on}</TableCell>
                  <TableCell>{grant.granted_days}</TableCell>
                  <TableCell>{grant.used_days}</TableCell>
                  <TableCell>{grant.remaining_days}</TableCell>
                  <TableCell className="text-muted-foreground">{grant.grant_reason ?? '-'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      <Card title="有給を申請する">
        <PaidLeaveRequestForm />
      </Card>

      <Card title="自分の有給申請">
        <MyPaidLeaveRequestList />
      </Card>
    </div>
  )
}
