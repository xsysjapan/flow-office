import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import { UserPicker } from '../components/UserPicker/UserPicker'
import type { PaidLeaveType } from '../api/types'
import {
  useCancelSpecialLeaveRequest,
  useCreateSpecialLeaveRequest,
  useMySpecialLeaveGrants,
  useMySpecialLeaveRequests,
  useSpecialLeaveTypes,
} from '../hooks/useSpecialLeave'
import { paidLeaveRequestStatusLabel, paidLeaveTypeLabel } from '../utils/statusLabels'

const LEAVE_TYPE_OPTIONS: Array<{ value: PaidLeaveType; label: string }> = [
  { value: 'full', label: '全休' },
  { value: 'am_half', label: '午前半休' },
  { value: 'pm_half', label: '午後半休' },
  { value: 'hourly', label: '時間休' },
]

function SpecialLeaveRequestForm() {
  const { data: types } = useSpecialLeaveTypes()
  const activeTypes = (types ?? []).filter((type) => type.is_active)

  const [specialLeaveTypeId, setSpecialLeaveTypeId] = useState<number | undefined>(undefined)
  const [targetDate, setTargetDate] = useState('')
  const [leaveType, setLeaveType] = useState<PaidLeaveType>('full')
  const [hours, setHours] = useState('')
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)
  const [reason, setReason] = useState('')

  const createRequest = useCreateSpecialLeaveRequest()

  const canSubmit = specialLeaveTypeId && targetDate && approverUserId && (leaveType !== 'hourly' || Number(hours) > 0)

  const handleSubmit = () => {
    if (!specialLeaveTypeId || !approverUserId) return

    createRequest.mutate(
      {
        special_leave_type_id: specialLeaveTypeId,
        target_date: targetDate,
        leave_type: leaveType,
        hours: leaveType === 'hourly' ? Number(hours) : undefined,
        approver_user_id: approverUserId,
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
        <FormField label="特別休暇の種類" htmlFor="special-leave-type" required>
          <NativeSelect
            id="special-leave-type"
            value={specialLeaveTypeId ?? ''}
            onChange={(e) => setSpecialLeaveTypeId(e.target.value ? Number(e.target.value) : undefined)}
          >
            <option value="">選択してください</option>
            {activeTypes.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="対象日" htmlFor="special-leave-target-date" required>
          <Input
            id="special-leave-target-date"
            type="date"
            value={targetDate}
            onChange={(e) => setTargetDate(e.target.value)}
          />
        </FormField>

        <FormField label="取得単位" htmlFor="special-leave-leave-type" required>
          <NativeSelect
            id="special-leave-leave-type"
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
          <FormField label="取得時間" htmlFor="special-leave-hours" required>
            <Input
              id="special-leave-hours"
              type="number"
              min="0.5"
              step="0.5"
              value={hours}
              onChange={(e) => setHours(e.target.value)}
            />
          </FormField>
        )}

        <FormField label="承認者" htmlFor="special-leave-approver" required>
          <UserPicker id="special-leave-approver" value={approverUserId} onChange={setApproverUserId} />
        </FormField>

        <FormField label="理由(任意)" htmlFor="special-leave-reason">
          <Input id="special-leave-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>

      <Button className="self-start" isLoading={createRequest.isPending} disabled={!canSubmit} onClick={handleSubmit}>
        申請する
      </Button>
    </div>
  )
}

function MySpecialLeaveRequestList() {
  const { data, isLoading, error } = useMySpecialLeaveRequests()
  const cancelRequest = useCancelSpecialLeaveRequest()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="特別休暇申請の取得に失敗しました。" />

  const requests = data ?? []

  if (requests.length === 0) return <p className="text-sm text-muted-foreground">特別休暇申請はまだありません。</p>

  return (
    <ul className="divide-y divide-border">
      {cancelRequest.error && <ErrorMessage error={cancelRequest.error} />}
      {requests.map((req) => {
        const { label, tone } = paidLeaveRequestStatusLabel(req.status)
        return (
          <li key={req.id} className="flex items-center justify-between gap-3 py-3">
            <div className="flex items-center gap-4 text-sm">
              <span className="text-foreground">{req.target_date}</span>
              <span className="text-muted-foreground">{req.special_leave_type_name}</span>
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
 * 特別休暇の残高確認・申請。有給休暇(MyPaidLeavePage)と同じ申請・承認・消化のUXだが、
 * 種別ごと(誕生日休暇など)に残高を管理する点が異なる。
 */
export function MySpecialLeavePage() {
  const { data, isLoading, error } = useMySpecialLeaveGrants()

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="特別休暇情報の取得に失敗しました。" />

  const grants = data ?? []
  const remainingByType = new Map<string, number>()
  for (const grant of grants) {
    const typeName = grant.special_leave_type_name ?? `種別#${grant.special_leave_type_id}`
    remainingByType.set(typeName, (remainingByType.get(typeName) ?? 0) + grant.remaining_days)
  }

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="自分の特別休暇"
        actions={
          <Button asChild variant="secondary">
            <Link to="/special-leave/history">履歴を見る</Link>
          </Button>
        }
      >
        {remainingByType.size === 0 ? (
          <p className="mb-4 text-sm text-muted-foreground">特別休暇の付与はまだありません。</p>
        ) : (
          <ul className="mb-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-foreground">
            {[...remainingByType.entries()].map(([typeName, remaining]) => (
              <li key={typeName}>
                {typeName}: 残り<strong className="mx-1 font-semibold">{remaining}</strong>日
              </li>
            ))}
          </ul>
        )}

        {grants.length > 0 && (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>種別</TableHead>
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
                  <TableCell>{grant.special_leave_type_name}</TableCell>
                  <TableCell>{grant.granted_on}</TableCell>
                  <TableCell>{grant.expires_on ?? 'なし'}</TableCell>
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

      <Card title="特別休暇を申請する">
        <SpecialLeaveRequestForm />
      </Card>

      <Card title="自分の特別休暇申請">
        <MySpecialLeaveRequestList />
      </Card>
    </div>
  )
}
