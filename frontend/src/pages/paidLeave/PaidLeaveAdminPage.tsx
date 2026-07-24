import { useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  useCreatePaidLeaveGrantRule,
  useGrantPaidLeave,
  usePaidLeaveGrantRules,
  usePaidLeaveGrantsForUser,
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
        <p className="text-sm text-muted-foreground">付与ルールはまだありません。</p>
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

      <Button isLoading={createRule.isPending} disabled={!ruleName} onClick={handleCreateRule}>
        ルールを作成
      </Button>
    </Card>
  )
}

function ManualGrantCard() {
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const [grantedOn, setGrantedOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantedDays, setGrantedDays] = useState('')
  const [grantReason, setGrantReason] = useState('')

  const grantPaidLeave = useGrantPaidLeave()
  const { data: userGrants, isLoading: isLoadingUserGrants } = usePaidLeaveGrantsForUser(userId ?? '')

  const handleGrant = () => {
    if (!userId) return
    grantPaidLeave.mutate({
      user_id: userId,
      granted_on: grantedOn,
      expires_on: expiresOn,
      granted_days: Number(grantedDays),
      grant_reason: grantReason || undefined,
    })
  }

  return (
    <Card title="手動付与">
      {grantPaidLeave.error && <ErrorMessage error={grantPaidLeave.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員" htmlFor="grant-target-user" required>
          <UserPicker id="grant-target-user" value={userId} onChange={setUserId} />
        </FormField>

        <FormField label="付与日" htmlFor="grant-granted-on" required>
          <Input id="grant-granted-on" type="date" value={grantedOn} onChange={(e) => setGrantedOn(e.target.value)} />
        </FormField>

        <FormField label="失効日" htmlFor="grant-expires-on" required>
          <Input id="grant-expires-on" type="date" value={expiresOn} onChange={(e) => setExpiresOn(e.target.value)} />
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

      <Button
        className="mt-4"
        isLoading={grantPaidLeave.isPending}
        disabled={!userId || !grantedOn || !expiresOn || !grantedDays}
        onClick={handleGrant}
      >
        付与する
      </Button>

      {userId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の有給付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">有給の付与はまだありません。</p>
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="py-2 text-sm text-foreground">
                  {grant.granted_on} 〜 {grant.expires_on} / 残{grant.remaining_days}日
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

/**
 * UC-P002: 有給付与ルールの設定と手動付与(管理者・人事向け)。
 */
export function PaidLeaveAdminPage() {
  return (
    <div className="flex flex-col gap-6">
      <PaidLeaveGrantRulesCard />
      <ManualGrantCard />
    </div>
  )
}
