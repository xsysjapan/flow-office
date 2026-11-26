import { describe, expect, it } from 'vitest'
import { isGroupFormDirty, type GroupFormValues } from './groupDetailDirty'

const base: GroupFormValues = {
  group_type_id: '1',
  name: '総務部',
  parent_group_id: '',
  status: 'active',
  initial_role_id: '',
}

describe('isGroupFormDirty', () => {
  it('変更なしはfalse', () => {
    expect(isGroupFormDirty(base, { ...base })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isGroupFormDirty(base, { ...base, name: '人事部' })).toBe(true)
    expect(isGroupFormDirty(base, { ...base, status: 'inactive' })).toBe(true)
  })
  it('元に戻すとfalse', () => {
    const changed = { ...base, name: '人事部' }
    expect(isGroupFormDirty(base, { ...changed, name: '総務部' })).toBe(false)
  })
})
