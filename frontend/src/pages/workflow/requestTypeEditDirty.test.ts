import { describe, expect, it } from 'vitest'
import { INITIAL_REQUEST_TYPE_FORM, isRequestTypeFormDirty } from './requestTypeEditDirty'

describe('isRequestTypeFormDirty', () => {
  const base = INITIAL_REQUEST_TYPE_FORM
  it('変更なしはfalse', () => {
    expect(isRequestTypeFormDirty(base, { ...base })).toBe(false)
  })
  it('1項目の変更はtrue(入力項目の行の変更も含む)', () => {
    expect(isRequestTypeFormDirty(base, { ...base, name: '経費' })).toBe(true)
    expect(isRequestTypeFormDirty(base, { ...base, isActive: false })).toBe(true)
    expect(
      isRequestTypeFormDirty(base, { ...base, formSchema: [{ key: 'a', label: 'A', type: 'text' }] }),
    ).toBe(true)
  })
  it('元に戻すとfalse(required の未指定とfalseは同じ)', () => {
    const withField = { ...base, formSchema: [{ key: 'a', label: 'A', type: 'text' as const }] }
    expect(
      isRequestTypeFormDirty(withField, {
        ...withField,
        formSchema: [{ key: 'a', label: 'A', type: 'text', required: false }],
      }),
    ).toBe(false)
    expect(isRequestTypeFormDirty(base, { ...base, name: '' })).toBe(false)
  })
})
