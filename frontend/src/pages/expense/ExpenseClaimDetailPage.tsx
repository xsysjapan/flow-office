import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Input } from '../../components/ui/input'
import { Separator } from '../../components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { ExpenseItem } from '../../api/types'
import { useWeek } from '../../hooks/useAttendance'
import {
  useApproveExpenseClaim,
  useCancelExpenseClaim,
  useExpenseClaim,
  useExpenseClaimHistory,
  useReturnExpenseClaim,
  useSubmitExpenseClaim,
} from '../../hooks/useExpenseClaims'
import { mondayOf, formatDate } from '../../utils/weekDates'
import {
  expenseClaimStatusLabel,
  workLocationTypeLabel,
  workflowRequestHistoryActionLabel,
} from '../../utils/statusLabels'

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

/**
 * UC-X011手順2: 明細のfact_reference(勤怠実績・予定・出張申請)を承認時の突合せ表示として
 * 参考表示する。勤怠実績への単一の"IDで取得"エンドポイントは無いため、明細のusage_date・
 * 対象社員IDで該当週を取得し、同じ日付の実績を探して表示する(勤怠側のデータは書き換えない)。
 */
function ItemReconciliation({ employeeId, item }: { employeeId: string; item: ExpenseItem }) {
  const weekStart = formatDate(mondayOf(new Date(`${item.usage_date}T00:00:00`)))
  const { data: week, isLoading } = useWeek(weekStart, employeeId)
  const day = week?.find((d) => d.work_date === item.usage_date)

  if (!item.fact_reference_type) {
    return <span className="text-xs text-muted-foreground">参照なし</span>
  }

  if (isLoading) return <span className="text-xs text-muted-foreground">確認中...</span>

  return (
    <span className="text-xs text-muted-foreground">
      {day?.work_location_type
        ? `${item.usage_date} ${workLocationTypeLabel(day.work_location_type)}と記録あり`
        : `${item.usage_date}の勤怠実績が見つかりません`}
    </span>
  )
}

/**
 * UC-X010/X011: 経費精算の詳細確認・提出・承認・差戻し・取消。承認者向けには
 * 各明細と対応する勤怠実績を並べて表示する突合せ表示を行う。
 */
export function ExpenseClaimDetailPage() {
  const { id } = useParams<{ id: string }>()
  const claimId = id ?? ''
  const { user } = useAuth()
  const { data: claim, isLoading, error } = useExpenseClaim(claimId)
  const { data: history, isLoading: isLoadingHistory } = useExpenseClaimHistory(claimId)

  const submitClaim = useSubmitExpenseClaim(claimId)
  const approveClaim = useApproveExpenseClaim(claimId)
  const returnClaim = useReturnExpenseClaim(claimId)
  const cancelClaim = useCancelExpenseClaim(claimId)

  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="経費精算の取得に失敗しました。" />
  if (!claim) return null

  const { label, tone } = expenseClaimStatusLabel(claim.status)
  const isApplicant = user?.id === claim.employee_id
  const isApprover = user?.id === claim.approver_user_id
  const actionError = submitClaim.error ?? approveClaim.error ?? returnClaim.error ?? cancelClaim.error

  return (
    <Card title={`経費精算(${claim.period_from} 〜 ${claim.period_to})`} actions={<Badge tone={tone}>{label}</Badge>}>
      {actionError && <ErrorMessage error={actionError} />}

      <div className="flex flex-col gap-6">
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <dt className="font-medium text-muted-foreground">申請者</dt>
          <dd className="text-foreground">{claim.employee?.name}</dd>
          <dt className="font-medium text-muted-foreground">承認者</dt>
          <dd className="text-foreground">{claim.approver?.name ?? '未指定'}</dd>
          <dt className="font-medium text-muted-foreground">合計金額</dt>
          <dd className="text-foreground">{claim.total_amount.toLocaleString()}円</dd>
        </dl>

        <div className="flex flex-col gap-2">
          <SectionHeading>{`明細(${claim.items.length}件)`}</SectionHeading>
          {claim.items.length === 0 ? (
            <p className="text-sm text-muted-foreground">明細はありません。</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>日付</TableHead>
                  <TableHead>経費区分</TableHead>
                  <TableHead>経路・内容</TableHead>
                  <TableHead>金額</TableHead>
                  <TableHead>勤怠実績との突合せ</TableHead>
                  <TableHead>領収書</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {claim.items.map((item) => {
                  const deductionAmount = item.commuting_deduction_amount ?? 0
                  return (
                    <TableRow key={item.id}>
                      <TableCell className="text-muted-foreground">{item.usage_date}</TableCell>
                      <TableCell className="text-muted-foreground">{item.category?.name}</TableCell>
                      <TableCell className="text-muted-foreground">
                        {item.origin && item.destination ? `${item.origin} → ${item.destination}` : item.purpose}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {item.amount.toLocaleString()}円
                        {deductionAmount > 0 && (
                          <span className="block text-xs">
                            定期区間控除 {deductionAmount.toLocaleString()}円・会社負担額{' '}
                            {(item.amount - deductionAmount).toLocaleString()}円
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        <ItemReconciliation employeeId={claim.employee_id} item={item} />
                      </TableCell>
                      <TableCell>
                        <AttachmentPanel
                          ownerType="expense_item"
                          ownerId={item.id}
                          readOnly={!isApplicant}
                          required={item.evidence_type === 'receipt_required'}
                          compact
                        />
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          )}
        </div>

        <div className="flex flex-col gap-2">
          <SectionHeading>履歴</SectionHeading>
          {isLoadingHistory ? (
            <LoadingState />
          ) : (
            <ul className="flex flex-col gap-1" aria-label="履歴">
              {history?.map((entry) => (
                <li key={entry.id} className="flex gap-3 text-sm">
                  <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(entry.occurred_at)}</span>
                  <span className="text-foreground">
                    {workflowRequestHistoryActionLabel(entry.action)}
                    {entry.comment ? `: ${entry.comment}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <Separator />

        <div className="flex flex-wrap items-center gap-3">
          {isApplicant && (claim.status === 'draft' || claim.status === 'returned') && (
            <div className="flex items-center gap-2">
              <UserPicker id="approver" value={approverUserId ?? claim.approver_user_id ?? undefined} onChange={setApproverUserId} />
              <Button
                isLoading={submitClaim.isPending}
                disabled={!(approverUserId ?? claim.approver_user_id)}
                onClick={() => submitClaim.mutate((approverUserId ?? claim.approver_user_id) as string)}
              >
                申請する
              </Button>
            </div>
          )}

          {isApplicant && ['draft', 'in_review', 'returned'].includes(claim.status) && (
            <div className="flex items-center gap-2">
              <Input placeholder="取消理由" value={reason} onChange={(e) => setReason(e.target.value)} />
              <Button
                variant="danger"
                isLoading={cancelClaim.isPending}
                disabled={!reason}
                onClick={() => cancelClaim.mutate(reason)}
              >
                取り消す
              </Button>
            </div>
          )}

          {isApprover && claim.status === 'in_review' && (
            <>
              <Button isLoading={approveClaim.isPending} onClick={() => approveClaim.mutate()}>
                承認する
              </Button>
              <div className="flex items-center gap-2">
                <Input placeholder="差戻しコメント" value={comment} onChange={(e) => setComment(e.target.value)} />
                <Button
                  variant="secondary"
                  isLoading={returnClaim.isPending}
                  disabled={!comment}
                  onClick={() => returnClaim.mutate(comment)}
                >
                  差戻す
                </Button>
              </div>
            </>
          )}
        </div>
      </div>
    </Card>
  )
}
