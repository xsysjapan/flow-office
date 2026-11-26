import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { BackLink } from '../../components/BackLink/BackLink'
import { useForwardState } from '../../navigation/backState'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
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
import { notify } from '../../lib/notify'
import { fieldErrors } from '../../utils/fieldErrors'
import { mondayOf, formatDate } from '../../utils/weekDates'
import {
  expenseClaimStatusLabel,
  isExpenseClaimCancellable,
  isExpenseClaimEditable,
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
  const forwardState = useForwardState()
  const { data: claim, isLoading, error } = useExpenseClaim(claimId)
  const { data: history, isLoading: isLoadingHistory } = useExpenseClaimHistory(claimId)

  const submitClaim = useSubmitExpenseClaim(claimId)
  const approveClaim = useApproveExpenseClaim(claimId)
  const returnClaim = useReturnExpenseClaim(claimId)
  const cancelClaim = useCancelExpenseClaim(claimId)

  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')
  const [cancelReasonError, setCancelReasonError] = useState<string | null>(null)
  const [approveOpen, setApproveOpen] = useState(false)

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="経費精算の取得に失敗しました。" />
  if (!claim) return null

  const { label, tone } = expenseClaimStatusLabel(claim.status)
  const submitErrors = fieldErrors(submitClaim.error, ['approver_user_id'])
  const cancelErrors = fieldErrors(cancelClaim.error, ['reason'])
  const returnErrors = fieldErrors(returnClaim.error, ['comment'])
  const isApplicant = user?.id === claim.employee_id
  const isApprover = user?.id === claim.approver_user_id

  const periodLabel = claim.period_from && claim.period_to ? `${claim.period_from} 〜 ${claim.period_to}` : '対象期間未確定'

  return (
    <div className="flex flex-col gap-4">
      <BackLink />

      <Card
        title={claim.title ? `${claim.title}(${periodLabel})` : `経費精算(${periodLabel})`}
        actions={<Badge tone={tone}>{label}</Badge>}
      >
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
                    <TableHead className="whitespace-normal min-w-48">内容</TableHead>
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
                        <TableCell className="whitespace-normal min-w-48 text-muted-foreground">{item.description}</TableCell>
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
            {isApplicant && isExpenseClaimEditable(claim.status) && (
              <Button asChild variant="secondary">
                <Link to={`/expenses/${claim.id}/edit`} state={forwardState}>明細を編集する</Link>
              </Button>
            )}

            {isApplicant && isExpenseClaimEditable(claim.status) && (
              <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                  <UserPicker id="approver" value={approverUserId ?? claim.approver_user_id ?? undefined} onChange={setApproverUserId} />
                  <Button
                    isLoading={submitClaim.isPending}
                    disabled={!(approverUserId ?? claim.approver_user_id)}
                    onClick={() =>
                      submitClaim.mutate((approverUserId ?? claim.approver_user_id) as string, {
                        onSuccess: () => notify.success('経費精算を提出しました'),
                      })
                    }
                  >
                    申請する
                  </Button>
                </div>
                {submitErrors.of('approver_user_id') && (
                  <p className="text-xs text-destructive">{submitErrors.of('approver_user_id')}</p>
                )}
                {!(approverUserId ?? claim.approver_user_id) && (
                  <p className="text-xs text-muted-foreground">承認者を選択してください</p>
                )}
                {submitErrors.rest != null && <ErrorMessage error={submitErrors.rest} />}
              </div>
            )}

            {isApplicant && isExpenseClaimCancellable(claim.status) && (
              <ConfirmActionDialog
                triggerLabel="取り消す"
                title="この経費精算を取り消しますか?"
                description="取り消すと、この経費精算は取消済みの状態になり元に戻せません。再度申請する場合は最初から作成し直してください。"
                confirmLabel="取り消しを確定する"
                isPending={cancelClaim.isPending}
                error={cancelErrors.rest}
                onOpenChange={(open) => {
                  if (open) {
                    setReason('')
                    setCancelReasonError(null)
                    cancelClaim.reset()
                  }
                }}
                onConfirm={async () => {
                  if (!reason) {
                    setCancelReasonError('取消理由を入力してください')
                    throw new Error('取消理由を入力してください')
                  }
                  setCancelReasonError(null)
                  await cancelClaim.mutateAsync(reason)
                  notify.success('経費精算を取り消しました')
                }}
              >
                <FormField
                  label="取消理由"
                  htmlFor="claim-cancel-reason"
                  required
                  error={cancelReasonError ?? cancelErrors.of('reason')}
                >
                  <Input
                    id="claim-cancel-reason"
                    placeholder="取消理由"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                </FormField>
              </ConfirmActionDialog>
            )}

            {isApprover && claim.status === 'in_review' && (
              <>
                <Button onClick={() => setApproveOpen(true)}>承認する</Button>
                <ConfirmActionDialog
                  open={approveOpen}
                  onOpenChange={(open) => {
                    setApproveOpen(open)
                    if (open) approveClaim.reset()
                  }}
                  title="この経費精算を承認しますか?"
                  description="承認すると取り消せません。申請者に承認の通知が届きます。"
                  confirmLabel="承認する"
                  confirmVariant="primary"
                  isPending={approveClaim.isPending}
                  error={approveClaim.error}
                  onConfirm={async () => {
                    await approveClaim.mutateAsync()
                    notify.success('経費精算を承認しました')
                  }}
                />
                <div className="flex flex-col gap-1">
                  <div className="flex items-center gap-2">
                    <Input placeholder="差戻しコメント" value={comment} onChange={(e) => setComment(e.target.value)} />
                    <Button
                      variant="secondary"
                      isLoading={returnClaim.isPending}
                      disabled={!comment}
                      onClick={() =>
                        returnClaim.mutate(comment, { onSuccess: () => notify.success('経費精算を差し戻しました') })
                      }
                    >
                      差戻す
                    </Button>
                  </div>
                  {returnErrors.of('comment') && (
                    <p className="text-xs text-destructive">{returnErrors.of('comment')}</p>
                  )}
                  {!comment && <p className="text-xs text-muted-foreground">差戻しコメントを入力してください</p>}
                  {returnErrors.rest != null && <ErrorMessage error={returnErrors.rest} />}
                </div>
              </>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}
