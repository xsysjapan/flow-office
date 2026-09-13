import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveGrantRulePreview } from '../../components/PaidLeaveGrantRulePreview/PaidLeaveGrantRulePreview'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { usePaidLeaveGrantPolicies, usePaidLeaveGrantRules, useUpdatePaidLeaveGrantRule } from '../../hooks/usePaidLeave'

interface StepInput {
  continuous_service_months: number
  grant_days: number
}

/** 指定した継続勤務月数に対する法定最低付与日数(通常付与表基準、`continuous_service_months`最大一致)。
 *  spec.md論点16の参照値表示用。work_style_id別の通常/比例判定はバックエンド専用の
 *  `GrantCategoryClassifier`に依存しフロントからは判定できないため、常に「通常付与」表を
 *  安全側(下回りを見逃さない)の参照値として表示する(実際の保存時バリデーションは
 *  バックエンド側`PaidLeaveController::validateStepsAgainstStatutoryMinimum`が正)。 */
function statutoryMinimumFor(months: number, normal: { continuous_service_months: number; grant_days: number }[]): number | undefined {
  return [...normal]
    .filter((s) => s.continuous_service_months <= months)
    .sort((a, b) => b.continuous_service_months - a.continuous_service_months)[0]?.grant_days
}

/**
 * 有給付与ルールの編集(spec.md論点15-1)。項目数が多く(基本設定4項目+繰り返しのsteps
 * サブテーブル)、Dialogに詰め込むと保存前に全体を見渡しづらくなるため、既存の作成フォームと
 * 同じくPageとして実装する(ui-interaction-patterns §2.11の「考慮が必要な作業」に該当する
 * という判断)。保存成功後は一覧(`PaidLeavePolicyPage`)へ戻る。
 */
export function PaidLeaveGrantRuleEditPage() {
  const { ruleId } = useParams<{ ruleId: string }>()
  const navigate = useNavigate()
  const { data: rules, isLoading, error } = usePaidLeaveGrantRules()
  const { data: policies } = usePaidLeaveGrantPolicies()
  const updateRule = useUpdatePaidLeaveGrantRule()

  const rule = rules?.find((r) => String(r.id) === ruleId)

  const [initialized, setInitialized] = useState(false)
  const [name, setName] = useState('')
  const [minAttendanceRate, setMinAttendanceRate] = useState('')
  const [firstGrantAfterMonths, setFirstGrantAfterMonths] = useState('')
  const [grantCycleMonths, setGrantCycleMonths] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [steps, setSteps] = useState<StepInput[]>([])
  const [stepMonths, setStepMonths] = useState('')
  const [stepDays, setStepDays] = useState('')

  useEffect(() => {
    if (!rule || initialized) return
    setName(rule.name)
    setMinAttendanceRate(String(rule.min_attendance_rate))
    setFirstGrantAfterMonths(String(rule.first_grant_after_months))
    setGrantCycleMonths(String(rule.grant_cycle_months))
    setIsActive(rule.is_active)
    setSteps(rule.steps ?? [])
    setInitialized(true)
  }, [rule, initialized])

  const handleAddStep = () => {
    if (!stepMonths || !stepDays) return
    setSteps((prev) => [...prev, { continuous_service_months: Number(stepMonths), grant_days: Number(stepDays) }])
    setStepMonths('')
    setStepDays('')
  }

  const handleRemoveStep = (index: number) => {
    setSteps((prev) => prev.filter((_, i) => i !== index))
  }

  const handleSave = () => {
    if (!rule) return
    updateRule.mutate(
      {
        id: rule.id,
        input: {
          name,
          min_attendance_rate: minAttendanceRate ? Number(minAttendanceRate) : undefined,
          first_grant_after_months: firstGrantAfterMonths ? Number(firstGrantAfterMonths) : undefined,
          grant_cycle_months: grantCycleMonths ? Number(grantCycleMonths) : undefined,
          is_active: isActive,
          steps,
        },
      },
      { onSuccess: () => navigate('/admin/paid-leave') },
    )
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="付与ルールの取得に失敗しました。" />
  if (!rule) return <ErrorMessage error={null} fallback="対象の付与ルールが見つかりません。" />

  const fieldErrors = updateRule.error && 'errors' in updateRule.error ? (updateRule.error as { errors?: Record<string, string[]> }).errors : undefined

  return (
    <Card title={`${rule.name}を編集`}>
      {updateRule.error && <ErrorMessage error={updateRule.error} />}

      <div className="mb-4 rounded-md border border-border bg-muted/30 p-3">
        <PaidLeaveGrantRulePreview
          firstGrantAfterMonths={Number(firstGrantAfterMonths) || 0}
          grantCycleMonths={Number(grantCycleMonths) || 0}
          minAttendanceRate={Number(minAttendanceRate) || 0}
          steps={steps}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="ルール名" htmlFor="edit-rule-name" required>
          <Input id="edit-rule-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>

        <FormField label="最低出勤率" htmlFor="edit-rule-min-attendance-rate">
          <Input
            id="edit-rule-min-attendance-rate"
            type="number"
            value={minAttendanceRate}
            onChange={(e) => setMinAttendanceRate(e.target.value)}
          />
        </FormField>

        <FormField label="初回付与までの月数" htmlFor="edit-rule-first-grant-after-months">
          <Input
            id="edit-rule-first-grant-after-months"
            type="number"
            value={firstGrantAfterMonths}
            onChange={(e) => setFirstGrantAfterMonths(e.target.value)}
          />
        </FormField>

        <FormField label="付与サイクル(月数)" htmlFor="edit-rule-grant-cycle-months">
          <Input
            id="edit-rule-grant-cycle-months"
            type="number"
            value={grantCycleMonths}
            onChange={(e) => setGrantCycleMonths(e.target.value)}
          />
        </FormField>
      </div>

      <label className="mt-2 mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <FormField label="継続勤務(か月)" htmlFor="edit-step-months">
          <Input id="edit-step-months" type="number" value={stepMonths} onChange={(e) => setStepMonths(e.target.value)} />
        </FormField>
        <FormField label="付与日数" htmlFor="edit-step-days">
          <Input id="edit-step-days" type="number" value={stepDays} onChange={(e) => setStepDays(e.target.value)} />
        </FormField>
        <Button variant="secondary" onClick={handleAddStep}>
          追加
        </Button>
      </div>

      {steps.length > 0 && (
        <ul className="mb-4 flex flex-col gap-1 text-sm text-muted-foreground">
          {steps.map((step, index) => {
            const minimum = policies ? statutoryMinimumFor(step.continuous_service_months, policies.normal) : undefined
            const fieldError = fieldErrors?.[`steps.${index}.grant_days`]?.[0]
            return (
              <li key={index} className="flex flex-wrap items-center gap-2">
                <span>
                  継続勤務{step.continuous_service_months}か月→{step.grant_days}日
                  {minimum !== undefined && <span className="ml-2 text-xs">(この継続勤務月数の法定最低日数: {minimum}日)</span>}
                </span>
                {fieldError && <span className="text-xs text-destructive">{fieldError}</span>}
                <Button variant="secondary" size="sm" onClick={() => handleRemoveStep(index)}>
                  削除
                </Button>
              </li>
            )
          })}
        </ul>
      )}
      <p className="mb-4 text-xs text-muted-foreground">法定最低日数以上であれば自由に設定できます。</p>

      <div className="flex gap-3">
        <Button variant="secondary" onClick={() => navigate('/admin/paid-leave')}>
          キャンセル
        </Button>
        <Button isLoading={updateRule.isPending} disabled={!name} onClick={handleSave}>
          保存
        </Button>
      </div>
    </Card>
  )
}
