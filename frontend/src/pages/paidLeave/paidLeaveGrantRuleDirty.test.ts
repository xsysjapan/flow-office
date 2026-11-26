import { describe, expect, it } from 'vitest'
import { isPaidLeaveGrantRuleDirty, type PaidLeaveGrantRuleFormValues } from './paidLeaveGrantRuleDirty'

const base: PaidLeaveGrantRuleFormValues = {
  name: '標準',
  minAttendanceRate: '80',
  firstGrantAfterMonths: '6',
  grantCycleMonths: '12',
  grantCycleType: 'anniversary',
  isActive: true,
  steps: [{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }],
}

describe('isPaidLeaveGrantRuleDirty', () => {
  it('is false when nothing changed', () => {
    expect(isPaidLeaveGrantRuleDirty(base, { ...base, steps: base.steps.map((s) => ({ ...s })) })).toBe(false)
  })
  it('is true when one field or a step changed', () => {
    expect(isPaidLeaveGrantRuleDirty(base, { ...base, isActive: false })).toBe(true)
    expect(isPaidLeaveGrantRuleDirty(base, { ...base, steps: [{ ...base.steps[0], grant_days: 11 }] })).toBe(true)
    expect(isPaidLeaveGrantRuleDirty(base, { ...base, steps: [] })).toBe(true)
  })
  it('is false again after reverting', () => {
    const changed = { ...base, name: 'x' }
    expect(isPaidLeaveGrantRuleDirty(base, changed)).toBe(true)
    expect(isPaidLeaveGrantRuleDirty(base, { ...changed, name: '標準' })).toBe(false)
  })
})
