import { describe, expect, it } from 'vitest'
import { isSingleExpenseItemFormDirty, SINGLE_EXPENSE_ITEM_FORM_INITIAL as base } from './singleExpenseItemFormDirty'

describe('isSingleExpenseItemFormDirty', () => {
  it('is false for the initial values', () => {
    expect(isSingleExpenseItemFormDirty(base, { ...base, attributeValues: {} })).toBe(false)
  })
  it('is true when one field, an attribute, or a receipt file changed', () => {
    expect(isSingleExpenseItemFormDirty(base, { ...base, amount: '100' })).toBe(true)
    expect(isSingleExpenseItemFormDirty(base, { ...base, paymentBearer: 'company' })).toBe(true)
    expect(isSingleExpenseItemFormDirty(base, { ...base, attributeValues: { a: 'x' } })).toBe(true)
    expect(isSingleExpenseItemFormDirty(base, { ...base, hasReceiptFile: true })).toBe(true)
  })
  it('is false again after reverting (empty or false attributes count as unfilled)', () => {
    const changed = { ...base, payee: 'ホテル', attributeValues: { a: 'x', b: true } }
    expect(isSingleExpenseItemFormDirty(base, changed)).toBe(true)
    expect(isSingleExpenseItemFormDirty(base, { ...base, attributeValues: { a: '', b: false } })).toBe(false)
  })
})
