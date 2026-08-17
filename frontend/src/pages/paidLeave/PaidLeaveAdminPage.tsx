import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { GrantTargetPicker, type GrantTargetMode } from '../../components/GrantTargetPicker/GrantTargetPicker'
import { LeaveHistoryList } from '../../components/LeaveHistoryList/LeaveHistoryList'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { RevokeGrantButton } from '../../components/RevokeGrantButton/RevokeGrantButton'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { runBulkGrant, type BulkGrantResult } from '../../lib/bulkGrant'
import {
  useCreatePaidLeaveGrantRule,
  useGrantPaidLeave,
  usePaidLeaveGrantRules,
  usePaidLeaveGrantsForUser,
  usePaidLeaveHistoryForUser,
  useRevokePaidLeaveGrant,
} from '../../hooks/usePaidLeave'

interface StepInput {
  continuous_service_months: number
  grant_days: number
}

function PaidLeaveGrantRulesCard() {
  const { data: rules, isLoading, error } = usePaidLeaveGrantRules()
  const createRule = useCreatePaidLeaveGrantRule()

  const [ruleName, setRuleName] = useState('')
  const [minAttendanceRate, setMinAttendanceRate] = useState('')
  const [firstGrantAfterMonths, setFirstGrantAfterMonths] = useState('')
  const [grantCycleMonths, setGrantCycleMonths] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [steps, setSteps] = useState<StepInput[]>([])
  const [stepMonths, setStepMonths] = useState('')
  const [stepDays, setStepDays] = useState('')

  const handleAddStep = () => {
    if (!stepMonths || !stepDays) return
    setSteps((prev) => [...prev, { continuous_service_months: Number(stepMonths), grant_days: Number(stepDays) }])
    setStepMonths('')
    setStepDays('')
  }

  const handleCreateRule = () => {
    createRule.mutate(
      {
        name: ruleName,
        min_attendance_rate: minAttendanceRate ? Number(minAttendanceRate) : undefined,
        first_grant_after_months: firstGrantAfterMonths ? Number(firstGrantAfterMonths) : undefined,
        grant_cycle_months: grantCycleMonths ? Number(grantCycleMonths) : undefined,
        is_active: isActive,
        steps: steps.length > 0 ? steps : undefined,
      },
      {
        onSuccess: () => {
          setRuleName('')
          setMinAttendanceRate('')
          setFirstGrantAfterMonths('')
          setGrantCycleMonths('')
          setIsActive(true)
          setSteps([])
        },
      },
    )
  }

  return (
    <Card title="付与ルール">
      {error && <ErrorMessage error={error} fallback="付与ルールの取得に失敗しました。" />}
      {createRule.error && <ErrorMessage error={createRule.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (rules ?? []).length === 0 ? (
        <EmptyState title="付与ルールはまだありません。" description="ルールを作成すると、対象社員へ自動的に有給が付与されます。" />
      ) : (
        <ul className="mb-5 divide-y divide-border">
          {(rules ?? []).map((rule) => (
            <li key={rule.id} className="py-3">
              <div className="flex items-center gap-3">
                <strong className="text-sm font-semibold text-foreground">{rule.name}</strong>
                <span className="text-sm text-muted-foreground">{rule.is_active ? '有効' : '無効'}</span>
              </div>
              <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                <dt className="font-medium text-muted-foreground">最低出勤率</dt>
                <dd className="text-foreground">{rule.min_attendance_rate}</dd>
                <dt className="font-medium text-muted-foreground">初回付与</dt>
                <dd className="text-foreground">{rule.first_grant_after_months}か月後</dd>
                <dt className="font-medium text-muted-foreground">付与サイクル</dt>
                <dd className="text-foreground">{rule.grant_cycle_months}か月ごと</dd>
              </dl>
              {rule.steps && rule.steps.length > 0 && (
                <ul className="mt-1 list-disc pl-4 text-sm text-muted-foreground">
                  {rule.steps.map((step, index) => (
                    <li key={index}>
                      継続勤務{step.continuous_service_months}か月→{step.grant_days}日
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">新しい付与ルールを作成</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="ルール名" htmlFor="rule-name" required>
          <Input id="rule-name" value={ruleName} onChange={(e) => setRuleName(e.target.value)} />
        </FormField>

        <FormField label="最低出勤率" htmlFor="rule-min-attendance-rate">
          <Input
            id="rule-min-attendance-rate"
            type="number"
            value={minAttendanceRate}
            onChange={(e) => setMinAttendanceRate(e.target.value)}
          />
        </FormField>

        <FormField label="初回付与までの月数" htmlFor="rule-first-grant-after-months">
          <Input
            id="rule-first-grant-after-months"
            type="number"
            value={firstGrantAfterMonths}
            onChange={(e) => setFirstGrantAfterMonths(e.target.value)}
          />
        </FormField>

        <FormField label="付与サイクル(月数)" htmlFor="rule-grant-cycle-months">
          <Input
            id="rule-grant-cycle-months"
            type="number"
            value={grantCycleMonths}
            onChange={(e) => setGrantCycleMonths(e.target.value)}
          />
        </FormField>
      </div>

      <label className="mt-4 mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <FormField label="継続勤務(か月)" htmlFor="step-months">
          <Input id="step-months" type="number" value={stepMonths} onChange={(e) => setStepMonths(e.target.value)} />
        </FormField>
        <FormField label="付与日数" htmlFor="step-days">
          <Input id="step-days" type="number" value={stepDays} onChange={(e) => setStepDays(e.target.value)} />
        </FormField>
        <Button variant="secondary" onClick={handleAddStep}>
          追加
        </Button>
      </div>

      {steps.length > 0 && (
        <ul className="mb-4 list-disc pl-4 text-sm text-muted-foreground">
          {steps.map((step, index) => (
            <li key={index}>
              継続勤務{step.continuous_service_months}か月→{step.grant_days}日
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-col items-start gap-1">
        <Button isLoading={createRule.isPending} disabled={!ruleName} onClick={handleCreateRule}>
          ルールを作成
        </Button>
        {!ruleName && <p className="text-xs text-muted-foreground">ルール名を入力してください。</p>}
      </div>
    </Card>
  )
}

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
  const [grantedOn, setGrantedOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantedDays, setGrantedDays] = useState('')
  const [grantReason, setGrantReason] = useState('')
  const [results, setResults] = useState<BulkGrantResult[] | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const grantPaidLeave = useGrantPaidLeave()
  const revokeGrant = useRevokePaidLeaveGrant()

  const singleTargetUserId = targetMode === 'individual' && targetIds.length === 1 ? targetIds[0] : undefined
  const { data: userGrants, isLoading: isLoadingUserGrants } = usePaidLeaveGrantsForUser(singleTargetUserId ?? '')

  const failedIds = results?.filter((r) => !r.success).map((r) => r.userId) ?? []

  const handleGrant = async () => {
    if (targetIds.length === 0 || !grantedOn || !expiresOn || !grantedDays) return
    setIsSubmitting(true)
    setResults(null)
    const outcomes = await runBulkGrant(targetIds, (userId) =>
      grantPaidLeave.mutateAsync({
        user_id: userId,
        granted_on: grantedOn,
        expires_on: expiresOn,
        granted_days: Number(grantedDays),
        grant_reason: grantReason || undefined,
      }),
    )
    setIsSubmitting(false)
    setResults(outcomes)
    if (outcomes.every((o) => o.success)) {
      setGrantedOn('')
      setExpiresOn('')
      setGrantedDays('')
      setGrantReason('')
    }
  }

  return (
    <Card title="手動付与">
      <FormField label="付与対象" htmlFor="grant-target-users" required>
        <GrantTargetPicker
          idPrefix="grant-target"
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
        <FormField label="付与日" htmlFor="grant-granted-on" required>
          <DatePicker id="grant-granted-on" value={grantedOn || undefined} onChange={(date) => setGrantedOn(date ?? '')} />
        </FormField>

        <FormField label="失効日" htmlFor="grant-expires-on" required>
          <DatePicker id="grant-expires-on" value={expiresOn || undefined} onChange={(date) => setExpiresOn(date ?? '')} />
        </FormField>

        <FormField label="付与日数" htmlFor="grant-granted-days" required>
          <Input
            id="grant-granted-days"
            type="number"
            value={grantedDays}
            onChange={(e) => setGrantedDays(e.target.value)}
          />
        </FormField>

        <FormField label="付与理由" htmlFor="grant-reason">
          <Input id="grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
        </FormField>
      </div>

      <div className="mt-4 flex flex-col items-start gap-1">
        <Button
          isLoading={isSubmitting}
          disabled={targetIds.length === 0 || !grantedOn || !expiresOn || !grantedDays}
          onClick={() => void handleGrant()}
        >
          {targetIds.length}名に付与する
        </Button>
        {targetIds.length === 0 ? (
          <p className="text-xs text-muted-foreground">付与対象を選択してください。</p>
        ) : !grantedOn ? (
          <p className="text-xs text-muted-foreground">付与日を選択してください。</p>
        ) : !expiresOn ? (
          <p className="text-xs text-muted-foreground">失効日を選択してください。</p>
        ) : !grantedDays ? (
          <p className="text-xs text-muted-foreground">付与日数を入力してください。</p>
        ) : null}
      </div>

      {results && <ResultSummary results={results} labels={targetLabels} />}

      {singleTargetUserId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の有給付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <EmptyState title="有給の付与はまだありません。" description="上のフォームから付与すると、ここに一覧が表示されます。" />
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm text-foreground">
                  <span>
                    {grant.granted_on} 〜 {grant.expires_on} / 残{grant.remaining_days}日
                    {grant.status === 'revoked' && (
                      <Badge tone="neutral">取消済み{grant.revoke_reason ? `(${grant.revoke_reason})` : ''}</Badge>
                    )}
                  </span>
                  {grant.status === 'active' && (
                    <RevokeGrantButton
                      id={`revoke-reason-${grant.id}`}
                      title="有給付与を取り消しますか?"
                      description={`${grant.granted_on}付与分(${grant.granted_days}日)を取り消します。この操作は元に戻せません。`}
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

function PaidLeaveHistoryCard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userId = searchParams.get('userId') ?? undefined
  const { data, isLoading, error } = usePaidLeaveHistoryForUser(userId ?? '')

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
    <Card title="有給履歴">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="paid-leave-history-user">
          <UserPicker id="paid-leave-history-user" value={userId} onChange={handleUserChange} />
        </FormField>
      </div>

      {userId === undefined ? (
        <EmptyState title="対象社員を選択してください。" description="社員を選ぶと、その社員の有給履歴を確認できます。" />
      ) : isEmpty ? (
        <EmptyState
          title="有給履歴はまだありません。"
          description="対象社員が有給を申請・付与されると、ここに履歴が表示されます。"
          action={
            <Button variant="secondary" onClick={() => handleUserChange(undefined)}>
              社員選択をクリア
            </Button>
          }
        />
      ) : (
        <LeaveHistoryList domain="paid_leave" events={data} isLoading={isLoading} error={error} />
      )}
    </Card>
  )
}

/**
 * UC-P002 / UC-P007: 有給付与ルールの設定・手動付与・対象社員の履歴確認・付与取消を
 * 1画面にまとめて管理者・人事向けに提供する。
 */
export function PaidLeaveAdminPage() {
  return (
    <div className="flex flex-col gap-6">
      <PaidLeaveGrantRulesCard />
      <ManualGrantCard />
      <PaidLeaveHistoryCard />
    </div>
  )
}
