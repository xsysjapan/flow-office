import { useState } from 'react'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import {
  useCreatePaidLeaveGrantRule,
  useGrantPaidLeave,
  usePaidLeaveGrantRules,
  usePaidLeaveGrantsForUser,
} from '../hooks/usePaidLeave'
import './PaidLeaveAdminPage.css'

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
        <p>付与ルールはまだありません。</p>
      ) : (
        <ul className="paid-leave-admin__rule-list">
          {(rules ?? []).map((rule) => (
            <li key={rule.id}>
              <div className="paid-leave-admin__rule-header">
                <strong>{rule.name}</strong>
                <span>{rule.is_active ? '有効' : '無効'}</span>
              </div>
              <dl className="paid-leave-admin__rule-meta">
                <dt>最低出勤率</dt>
                <dd>{rule.min_attendance_rate}</dd>
                <dt>初回付与</dt>
                <dd>{rule.first_grant_after_months}か月後</dd>
                <dt>付与サイクル</dt>
                <dd>{rule.grant_cycle_months}か月ごと</dd>
              </dl>
              {rule.steps && rule.steps.length > 0 && (
                <ul className="paid-leave-admin__rule-steps">
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

      <h3>新しい付与ルールを作成</h3>

      <FormField label="ルール名" htmlFor="rule-name" required>
        <input id="rule-name" value={ruleName} onChange={(e) => setRuleName(e.target.value)} />
      </FormField>

      <FormField label="最低出勤率" htmlFor="rule-min-attendance-rate">
        <input
          id="rule-min-attendance-rate"
          type="number"
          value={minAttendanceRate}
          onChange={(e) => setMinAttendanceRate(e.target.value)}
        />
      </FormField>

      <FormField label="初回付与までの月数" htmlFor="rule-first-grant-after-months">
        <input
          id="rule-first-grant-after-months"
          type="number"
          value={firstGrantAfterMonths}
          onChange={(e) => setFirstGrantAfterMonths(e.target.value)}
        />
      </FormField>

      <FormField label="付与サイクル(月数)" htmlFor="rule-grant-cycle-months">
        <input
          id="rule-grant-cycle-months"
          type="number"
          value={grantCycleMonths}
          onChange={(e) => setGrantCycleMonths(e.target.value)}
        />
      </FormField>

      <label className="paid-leave-admin__checkbox">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
        有効
      </label>

      <div className="paid-leave-admin__steps-editor">
        <FormField label="継続勤務(か月)" htmlFor="step-months">
          <input id="step-months" type="number" value={stepMonths} onChange={(e) => setStepMonths(e.target.value)} />
        </FormField>
        <FormField label="付与日数" htmlFor="step-days">
          <input id="step-days" type="number" value={stepDays} onChange={(e) => setStepDays(e.target.value)} />
        </FormField>
        <Button variant="secondary" onClick={handleAddStep}>
          追加
        </Button>
      </div>

      {steps.length > 0 && (
        <ul className="paid-leave-admin__rule-steps">
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
  const [userId, setUserId] = useState<number | undefined>(undefined)
  const [grantedOn, setGrantedOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantedDays, setGrantedDays] = useState('')
  const [grantReason, setGrantReason] = useState('')

  const grantPaidLeave = useGrantPaidLeave()
  const { data: userGrants, isLoading: isLoadingUserGrants } = usePaidLeaveGrantsForUser(userId ?? NaN)

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

      <FormField label="対象社員" htmlFor="grant-target-user" required>
        <UserPicker id="grant-target-user" value={userId} onChange={setUserId} />
      </FormField>

      <FormField label="付与日" htmlFor="grant-granted-on" required>
        <input id="grant-granted-on" type="date" value={grantedOn} onChange={(e) => setGrantedOn(e.target.value)} />
      </FormField>

      <FormField label="失効日" htmlFor="grant-expires-on" required>
        <input id="grant-expires-on" type="date" value={expiresOn} onChange={(e) => setExpiresOn(e.target.value)} />
      </FormField>

      <FormField label="付与日数" htmlFor="grant-granted-days" required>
        <input
          id="grant-granted-days"
          type="number"
          value={grantedDays}
          onChange={(e) => setGrantedDays(e.target.value)}
        />
      </FormField>

      <FormField label="付与理由" htmlFor="grant-reason">
        <input id="grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
      </FormField>

      <Button
        isLoading={grantPaidLeave.isPending}
        disabled={!userId || !grantedOn || !expiresOn || !grantedDays}
        onClick={handleGrant}
      >
        付与する
      </Button>

      {userId !== undefined && (
        <div className="paid-leave-admin__user-grants">
          <h3>対象社員の有給付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <p>有給の付与はまだありません。</p>
          ) : (
            <ul className="paid-leave-admin__user-grant-list">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id}>
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
    <div className="paid-leave-admin">
      <PaidLeaveGrantRulesCard />
      <ManualGrantCard />
    </div>
  )
}
