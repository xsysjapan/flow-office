import type { PaidLeaveGrantRuleStep } from '../../api/types'

export interface PaidLeaveGrantRuleFormValues {
  name: string
  minAttendanceRate: string
  firstGrantAfterMonths: string
  grantCycleMonths: string
  grantCycleType: string
  isActive: boolean
  steps: PaidLeaveGrantRuleStep[]
}

/** 有給付与ルールの編集: 読み込んだ値と今の入力を比べる。 */
export function isPaidLeaveGrantRuleDirty(
  base: PaidLeaveGrantRuleFormValues,
  current: PaidLeaveGrantRuleFormValues,
): boolean {
  return (
    base.name !== current.name ||
    base.minAttendanceRate !== current.minAttendanceRate ||
    base.firstGrantAfterMonths !== current.firstGrantAfterMonths ||
    base.grantCycleMonths !== current.grantCycleMonths ||
    base.grantCycleType !== current.grantCycleType ||
    base.isActive !== current.isActive ||
    base.steps.length !== current.steps.length ||
    base.steps.some((step, i) => {
      const other = current.steps[i]
      return (
        step.grant_category !== other.grant_category ||
        step.continuous_service_months !== other.continuous_service_months ||
        step.grant_days !== other.grant_days
      )
    })
  )
}
