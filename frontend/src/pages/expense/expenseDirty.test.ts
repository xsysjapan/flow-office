import { describe, expect, it } from 'vitest'
import { EXPENSE_CLAIM_NEW_INITIAL, isExpenseClaimNewDirty } from './expenseClaimNewDirty'
import { EXPENSE_CATEGORY_INITIAL, isExpenseCategoryDirty } from './expenseCategoryDirty'
import { EXPENSE_ENTRY_PRESET_INITIAL, isExpenseEntryPresetDirty } from './expenseEntryPresetDirty'

describe('isExpenseCategoryDirty', () => {
  const base = EXPENSE_CATEGORY_INITIAL
  it('is false when nothing changed', () => {
    expect(isExpenseCategoryDirty(base, { ...base })).toBe(false)
  })
  it('is true when one field changed, false after reverting', () => {
    const changed = { ...base, name: '交通費' }
    expect(isExpenseCategoryDirty(base, changed)).toBe(true)
    expect(isExpenseCategoryDirty(base, { ...changed, name: '' })).toBe(false)
  })
  it('detects added field definitions and ignores required undefined vs false', () => {
    const withField = { ...base, fieldDefinitions: [{ key: 'a', label: 'A', type: 'text' as const }] }
    expect(isExpenseCategoryDirty(base, withField)).toBe(true)
    expect(
      isExpenseCategoryDirty(withField, {
        ...base,
        fieldDefinitions: [{ key: 'a', label: 'A', type: 'text' as const, required: false }],
      }),
    ).toBe(false)
  })
})

describe('isExpenseEntryPresetDirty', () => {
  const base = EXPENSE_ENTRY_PRESET_INITIAL
  it('is false when nothing changed', () => {
    expect(isExpenseEntryPresetDirty(base, { ...base, items: [{ rowId: 9, category_id: 0, amount: null }] })).toBe(false)
  })
  it('is true when one field or an item changed, false after reverting', () => {
    const changed = { ...base, name: 'x' }
    expect(isExpenseEntryPresetDirty(base, changed)).toBe(true)
    expect(isExpenseEntryPresetDirty(base, { ...changed, name: '' })).toBe(false)
    expect(isExpenseEntryPresetDirty(base, { ...base, items: [{ category_id: 2, amount: null }] })).toBe(true)
    expect(isExpenseEntryPresetDirty(base, { ...base, items: [...base.items, { category_id: 0 }] })).toBe(true)
  })
})

describe('isExpenseClaimNewDirty', () => {
  const base = EXPENSE_CLAIM_NEW_INITIAL
  const row = { category_id: 1, usage_date: '', amount: 0 }
  it('is false for the initial values and for an empty added row', () => {
    expect(isExpenseClaimNewDirty(base, { ...base })).toBe(false)
    expect(isExpenseClaimNewDirty(base, { ...base, rows: [row] })).toBe(false)
  })
  it('is true when a row is filled, a title typed, or an approver chosen', () => {
    expect(isExpenseClaimNewDirty(base, { ...base, rows: [{ ...row, amount: 100 }] })).toBe(true)
    expect(isExpenseClaimNewDirty(base, { ...base, bulkTitle: '出張' })).toBe(true)
    expect(isExpenseClaimNewDirty(base, { ...base, approverUserId: 'u1' })).toBe(true)
  })
  it('is false again after reverting', () => {
    const changed = { ...base, bulkTitle: '出張' }
    expect(isExpenseClaimNewDirty(base, changed)).toBe(true)
    expect(isExpenseClaimNewDirty(base, { ...changed, bulkTitle: '' })).toBe(false)
  })
})
