import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
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
  useCancelCompensatoryLeaveRequest,
  useCreateCompensatoryLeaveRequest,
  useMyCompensatoryLeaveGrants,
  useMyCompensatoryLeaveRequests,
} from '../../hooks/useCompensatoryLeave'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../../utils/statusLabels'

const LEAVE_TYPE_OPTIONS: Array<{ value: PaidLeaveType; label: string }> = [
  { value: 'full', label: '全休' },
  { value: 'am_half', label: '午前半休' },
  { value: 'pm_half', label: '午後半休' },
  { value: 'hourly', label: '時間休' },
]

function formatMinutes(minutes: number | null): string {
  return minutes === null ? '-' : String(minutes)
}

function CompensatoryLeaveRequestForm() {
  const { systemSettings } = useAppSettings()
  const approvalRequired = systemSettings.compensatory_leave_requires_approval

  const [targetDate, setTargetDate] = useState('')
  const [leaveType, setLeaveType] = useState<PaidLeaveType>('full')
  const [hours, setHours] = useState('')
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const [reason, setReason] = useState('')

  const createRequest = useCreateCompensatoryLeaveRequest()

  const canSubmit =
    targetDate && (!approvalRequired || approverUserId) && (leaveType !== 'hourly' || Number(hours) > 0)

  const handleSubmit = () => {
    if (approvalRequired && !approverUserId) return

    createRequest.mutate(
      {
        target_date: targetDate,
        leave_type: leaveType,
        hours: leaveType === 'hourly' ? Number(hours) : undefined,
        approver_user_id: approverUserId || undefined,
        reason: reason || undefined,
      },
      {
        onSuccess: () => {
          setTargetDate('')
          setHours('')
          setApproverUserId(undefined)
          setReason('')
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {createRequest.error && <ErrorMessage error={createRequest.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象日" htmlFor="compensatory-leave-target-date" required>
          <DatePicker
            id="compensatory-leave-target-date"
            value={targetDate || undefined}
            onChange={(date) => setTargetDate(date ?? '')}
          />
        </FormField>

        <FormField label="取得単位" htmlFor="compensatory-leave-type" required>
          <NativeSelect
            id="compensatory-leave-type"
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

        {leaveType === 'hourly' && (
          <FormField label="取得時間" htmlFor="compensatory-leave-hours" required>
            <Input
              id="compensatory-leave-hours"
              type="number"
              min="0.5"
              step="0.5"
              value={hours}
              onChange={(e) => setHours(e.target.value)}
            />
          </FormField>
        )}

        <FormField
          label={approvalRequired ? '承認者' : '承認者(任意)'}
          htmlFor="compensatory-leave-approver"
          required={approvalRequired}
        >
          <UserPicker id="compensatory-leave-approver" value={approverUserId} onChange={setApproverUserId} />
          {!approvalRequired && (
            <p className="mt-1 text-xs text-muted-foreground">
              現在の設定では代休申請に承認は不要です。申請すると同時に確定します。承認者の指定は任意です。
            </p>
          )}
        </FormField>

        <FormField label="理由(任意)" htmlFor="compensatory-leave-reason">
          <Input id="compensatory-leave-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>

      <Button className="self-start" isLoading={createRequest.isPending} disabled={!canSubmit} onClick={handleSubmit}>
        申請する
      </Button>
    </div>
  )
}

function MyCompensatoryLeaveRequestList() {
  const { data, isLoading, error } = useMyCompensatoryLeaveRequests()
  const cancelRequest = useCancelCompensatoryLeaveRequest()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="代休申請の取得に失敗しました。" />

  const requests = data ?? []

  if (requests.length === 0) return <p className="text-sm text-muted-foreground">代休申請はまだありません。</p>

  return (
    <ul className="divide-y divide-border">
      {cancelRequest.error && <ErrorMessage error={cancelRequest.error} />}
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
              <Button variant="secondary" isLoading={cancelRequest.isPending} onClick={() => cancelRequest.mutate(req.id)}>
                取消
              </Button>
            )}
          </li>
        )
      })}
    </ul>
  )
}

/**
 * 自分の代休残数(付与)を確認し、消化申請・自分の申請一覧の取消を行う画面。
 * 付与は休日出勤の勤怠実績から自動導出されるため(App\Domain\CompensatoryLeave)、
 * 付与のCRUD・履歴画面・管理者向け画面はこの画面の対象外。
 */
export function MyCompensatoryLeavePage() {
  const { data, isLoading, error } = useMyCompensatoryLeaveGrants()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="代休情報の取得に失敗しました。" />

  const grants = data ?? []
  const totalRemaining = grants.reduce((sum, grant) => sum + grant.remaining_days, 0)

  return (
    <div className="flex flex-col gap-6">
      <Card title="自分の代休">
        <p className="mb-4 text-sm text-foreground">
          残り<strong className="mx-1 text-lg font-semibold">{totalRemaining}</strong>日
        </p>

        {grants.length === 0 ? (
          <p className="text-sm text-muted-foreground">代休の付与はまだありません。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>出勤日</TableHead>
                <TableHead>失効日</TableHead>
                <TableHead>付与日数</TableHead>
                <TableHead>使用日数</TableHead>
                <TableHead>残日数</TableHead>
                <TableHead>残分</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {grants.map((grant) => (
                <TableRow key={grant.id}>
                  <TableCell>{grant.work_date}</TableCell>
                  <TableCell>{grant.expires_on ?? '-'}</TableCell>
                  <TableCell>{grant.granted_days}</TableCell>
                  <TableCell>{grant.used_days}</TableCell>
                  <TableCell>{grant.remaining_days}</TableCell>
                  <TableCell>{formatMinutes(grant.remaining_minutes)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      <Card title="代休を申請する">
        <CompensatoryLeaveRequestForm />
      </Card>

      <Card title="自分の代休申請">
        <MyCompensatoryLeaveRequestList />
      </Card>
    </div>
  )
}
