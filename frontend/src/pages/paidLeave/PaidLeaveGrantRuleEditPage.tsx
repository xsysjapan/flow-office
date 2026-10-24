import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import type { PaidLeaveGrantCycleType, PaidLeaveGrantRuleStep } from '../../api/types'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PaidLeaveGrantCycleTypeField } from '../../components/PaidLeaveGrantCycleTypeField/PaidLeaveGrantCycleTypeField'
import { PaidLeaveGrantRulePreview } from '../../components/PaidLeaveGrantRulePreview/PaidLeaveGrantRulePreview'
import { PaidLeaveGrantStepsEditor } from '../../components/PaidLeaveGrantStepsEditor/PaidLeaveGrantStepsEditor'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { useWorkCalendars } from '../../hooks/useWorkCalendars'
import { usePaidLeaveGrantPolicies, usePaidLeaveGrantRules, useUpdatePaidLeaveGrantRule } from '../../hooks/usePaidLeave'
import { DEFAULT_FISCAL_YEAR_START, pickDefaultFiscalYearStart } from '../../lib/paidLeaveGrantRule'

/**
 * 有給付与ルールの編集(spec.md論点15-1)。項目数が多く(基本設定4項目+付与方式+区分ごとの表)、
 * Dialogに詰め込むと保存前に全体を見渡しづらくなるため、既存の作成フォームと同じくPageとして
 * 実装する(ui-interaction-patterns §2.11の「考慮が必要な作業」に該当するという判断)。
 * 保存成功後は一覧(`PaidLeavePolicyPage`)へ戻る。
 */
export function PaidLeaveGrantRuleEditPage() {
  const { ruleId } = useParams<{ ruleId: string }>()
  const navigate = useNavigate()
  const { data: rules, isLoading, error } = usePaidLeaveGrantRules()
  const { data: policies } = usePaidLeaveGrantPolicies()
  const { data: calendars, isLoading: isLoadingCalendars } = useWorkCalendars()
  const updateRule = useUpdatePaidLeaveGrantRule()

  const rule = rules?.find((r) => String(r.id) === ruleId)
  const defaultFiscalYearStart = isLoadingCalendars ? undefined : pickDefaultFiscalYearStart(calendars)
  const previewFiscalYearStart = defaultFiscalYearStart ?? DEFAULT_FISCAL_YEAR_START

  const [initialized, setInitialized] = useState(false)
  const [name, setName] = useState('')
  const [minAttendanceRate, setMinAttendanceRate] = useState('')
  const [firstGrantAfterMonths, setFirstGrantAfterMonths] = useState('')
  const [grantCycleMonths, setGrantCycleMonths] = useState('')
  const [grantCycleType, setGrantCycleType] = useState<PaidLeaveGrantCycleType>('mass_grant_month')
  const [isActive, setIsActive] = useState(true)
  const [steps, setSteps] = useState<PaidLeaveGrantRuleStep[]>([])

  useEffect(() => {
    if (!rule || initialized) return
    setName(rule.name)
    setMinAttendanceRate(String(rule.min_attendance_rate))
    setFirstGrantAfterMonths(String(rule.first_grant_after_months))
    setGrantCycleMonths(String(rule.grant_cycle_months))
    setGrantCycleType(rule.grant_cycle_type)
    setIsActive(rule.is_active)
    setSteps(rule.steps ?? [])
    setInitialized(true)
  }, [rule, initialized])

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
          grant_cycle_type: grantCycleType,
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

  const fieldErrors = updateRule.error instanceof ApiError ? updateRule.error.errors : undefined

  return (
    <Card title={`${rule.name}を編集`}>
      {updateRule.error && <ErrorMessage error={updateRule.error} />}

      <div className="mb-4 rounded-md border border-border bg-muted/30 p-3">
        <PaidLeaveGrantRulePreview
          grantCycleType={grantCycleType}
          fiscalYearStart={previewFiscalYearStart}
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

      <PaidLeaveGrantCycleTypeField
        value={grantCycleType}
        onChange={setGrantCycleType}
        defaultFiscalYearStart={defaultFiscalYearStart}
      />

      <label className="mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <PaidLeaveGrantStepsEditor steps={steps} onChange={setSteps} statutory={policies} fieldErrors={fieldErrors} />
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
