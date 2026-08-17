import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { FormField } from '../../components/FormField/FormField'
import { GrantTargetPicker, type GrantTargetMode } from '../../components/GrantTargetPicker/GrantTargetPicker'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { RevokeGrantButton } from '../../components/RevokeGrantButton/RevokeGrantButton'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Input } from '../../components/ui/input'
import {
  useCompensatoryLeaveGrantsForUser,
  useCompensatoryLeaveHistoryForUser,
  useGrantCompensatoryLeave,
  useRevokeCompensatoryLeaveGrant,
} from '../../hooks/useCompensatoryLeave'
import { runBulkGrant, type BulkGrantResult } from '../../lib/bulkGrant'

function ResultSummary({ results, labels }: { results: BulkGrantResult[]; labels: Record<string, string> }) {
  const failures = results.filter((r) => !r.success)
  return (
    <div className="mt-4 rounded-md border border-border p-3 text-sm">
      <p className="font-medium text-foreground">
        {results.length - failures.length}件成功 / {failures.length}件失敗
      </p>
      {failures.length > 0 && (
        <ul className="mt-2 list-disc pl-4 text-destructive">
          {failures.map((failure) => (
            <li key={failure.userId}>
              {labels[failure.userId] ?? failure.userId}: {failure.message}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function ManualGrantCard() {
  const [targetIds, setTargetIds] = useState<string[]>([])
  const [targetMode, setTargetMode] = useState<GrantTargetMode>('individual')
  const [targetLabels, setTargetLabels] = useState<Record<string, string>>({})
  const [workDate, setWorkDate] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantReason, setGrantReason] = useState('')
  const [results, setResults] = useState<BulkGrantResult[] | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const grantCompensatoryLeave = useGrantCompensatoryLeave()
  const revokeGrant = useRevokeCompensatoryLeaveGrant()

  const singleTargetUserId = targetMode === 'individual' && targetIds.length === 1 ? targetIds[0] : undefined
  const { data: userGrants, isLoading: isLoadingUserGrants } = useCompensatoryLeaveGrantsForUser(singleTargetUserId ?? '')

  const failedIds = results?.filter((r) => !r.success).map((r) => r.userId) ?? []

  const handleGrant = async () => {
    if (targetIds.length === 0 || !workDate) return
    setIsSubmitting(true)
    setResults(null)
    const outcomes = await runBulkGrant(targetIds, (userId) =>
      grantCompensatoryLeave.mutateAsync({
        user_id: userId,
        work_date: workDate,
        expires_on: expiresOn || undefined,
        grant_reason: grantReason || undefined,
      }),
    )
    setIsSubmitting(false)
    setResults(outcomes)
    if (outcomes.every((o) => o.success)) {
      setWorkDate('')
      setExpiresOn('')
      setGrantReason('')
    }
  }

  return (
    <Card title="手動付与">
      <FormField label="付与対象" htmlFor="compensatory-leave-grant-target-users" required>
        <GrantTargetPicker
          idPrefix="compensatory-leave-grant-target"
          onResolvedChange={(ids, mode, labels) => {
            setTargetMode(mode)
            setTargetIds(ids)
            setTargetLabels(labels)
          }}
          resetSignal={results}
          resetIndividualIds={failedIds}
        />
      </FormField>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="休日出勤の実績日" htmlFor="compensatory-leave-grant-work-date" required>
          <DatePicker
            id="compensatory-leave-grant-work-date"
            value={workDate || undefined}
            onChange={(date) => setWorkDate(date ?? '')}
          />
        </FormField>

        <FormField label="失効日(空欄なら失効しない)" htmlFor="compensatory-leave-grant-expires-on">
          <DatePicker
            id="compensatory-leave-grant-expires-on"
            value={expiresOn || undefined}
            onChange={(date) => setExpiresOn(date ?? '')}
          />
        </FormField>

        <FormField label="付与理由" htmlFor="compensatory-leave-grant-reason">
          <Input id="compensatory-leave-grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
        </FormField>
      </div>

      <p className="mt-2 text-xs text-muted-foreground">
        付与日数はサーバー側で実績日から自動的に算出されます。指定した日が休日出勤の実績でない場合は付与に失敗します。
      </p>

      <div className="mt-4 flex flex-col items-start gap-1">
        <Button
          isLoading={isSubmitting}
          disabled={targetIds.length === 0 || !workDate}
          onClick={() => void handleGrant()}
        >
          {targetIds.length}名に付与する
        </Button>
        {targetIds.length === 0 ? (
          <p className="text-xs text-muted-foreground">付与対象を選択してください。</p>
        ) : !workDate ? (
          <p className="text-xs text-muted-foreground">休日出勤の実績日を選択してください。</p>
        ) : null}
      </div>

      {results && <ResultSummary results={results} labels={targetLabels} />}

      {singleTargetUserId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の代休付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <EmptyState title="代休の付与はまだありません。" description="上のフォームから付与すると、ここに一覧が表示されます。" />
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm text-foreground">
                  <span>
                    {grant.work_date}({grant.source === 'manual' ? '手動' : '自動'}) / 残{grant.remaining_days}日
                    {grant.status === 'cancelled' && <Badge tone="neutral">取消済み</Badge>}
                  </span>
                  {grant.status !== 'cancelled' && (
                    <RevokeGrantButton
                      id={`revoke-reason-${grant.id}`}
                      title="代休付与を取り消しますか?"
                      description={`${grant.work_date}分の代休付与(${grant.granted_days}日)を取り消します。この操作は元に戻せません。`}
                      isPending={revokeGrant.isPending}
                      error={revokeGrant.error}
                      onRevoke={(reason) => revokeGrant.mutateAsync({ grantId: grant.id, reason })}
                      disabled={grant.used_days > 0}
                      disabledReason="既に消化された分は取り消せません。"
                    />
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

function CompensatoryLeaveHistoryCard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userId = searchParams.get('userId') ?? undefined
  const { data, isLoading, error } = useCompensatoryLeaveHistoryForUser(userId ?? '')

  const handleUserChange = (value: string | undefined) => {
    const next = new URLSearchParams(searchParams)
    if (value) {
      next.set('userId', value)
    } else {
      next.delete('userId')
    }
    setSearchParams(next, { replace: true })
  }

  const isEmpty = userId !== undefined && !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="代休履歴">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="compensatory-leave-history-user">
          <UserPicker id="compensatory-leave-history-user" value={userId} onChange={handleUserChange} />
        </FormField>
      </div>

      {userId === undefined ? (
        <EmptyState title="対象社員を選択してください。" description="社員を選ぶと、その社員の代休履歴を確認できます。" />
      ) : isEmpty ? (
        <EmptyState
          title="代休履歴はまだありません。"
          description="対象社員が代休を申請・付与されると、ここに履歴が表示されます。"
          action={
            <Button variant="secondary" onClick={() => handleUserChange(undefined)}>
              社員選択をクリア
            </Button>
          }
        />
      ) : (
        <LeaveHistoryList domain="compensatory_leave" events={data} isLoading={isLoading} error={error} />
      )}
    </Card>
  )
}

/**
 * 代休の手動付与・履歴確認(管理者・人事向け)。有給・特別休暇と異なり自動付与ルール・
 * 種類マスタは無く、休日出勤の実績日を指定した手動付与のみを扱う。付与状況一覧(対象社員
 * 選択時に表示される、現在の残数確認用)に加え、対象社員を選んで付与・申請・承認・消化等の
 * イベント履歴を時系列で確認できる履歴カードを提供する(有給・特別休暇の履歴カードと同じ形)。
 */
export function CompensatoryLeaveAdminPage() {
  return (
    <div className="flex flex-col gap-6">
      <ManualGrantCard />
      <CompensatoryLeaveHistoryCard />
    </div>
  )
}
