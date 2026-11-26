import { describe, expect, it } from 'vitest'
import { isWorkflowRequestNewDirty, type WorkflowRequestNewValues } from './workflowRequestNewDirty'

const base: WorkflowRequestNewValues = {
  requestTypeCode: 'expense_reimbursement',
  title: '出張費',
  formValues: { amount: '1000' },
  approverUserId: undefined,
}

describe('isWorkflowRequestNewDirty', () => {
  it('変更なしはfalse', () => {
    expect(isWorkflowRequestNewDirty(base, { ...base, formValues: { ...base.formValues } })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isWorkflowRequestNewDirty(base, { ...base, title: '交通費' })).toBe(true)
    expect(isWorkflowRequestNewDirty(base, { ...base, formValues: { amount: '2000' } })).toBe(true)
    expect(isWorkflowRequestNewDirty(base, { ...base, approverUserId: 'u1' })).toBe(true)
    expect(isWorkflowRequestNewDirty(base, { ...base, requestTypeCode: '' })).toBe(true)
  })
  it('元に戻すとfalse(空欄は未入力と同じ)', () => {
    expect(isWorkflowRequestNewDirty(base, { ...base, formValues: { amount: '1000', note: '' } })).toBe(false)
    expect(isWorkflowRequestNewDirty(base, { ...base, title: '出張費' })).toBe(false)
  })
})
