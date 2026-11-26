import { describe, expect, it } from 'vitest'
import { isRoleDefinitionDirty, type RoleDefinitionValues } from './roleDefinitionDirty'

const base: RoleDefinitionValues = {
  name: '管理者',
  description: '',
  status: 'active',
  permissionIds: [1, 2],
  featureIds: [10],
}

describe('isRoleDefinitionDirty', () => {
  it('変更なしはfalse(並び順が違ってもfalse)', () => {
    expect(isRoleDefinitionDirty(base, { ...base })).toBe(false)
    expect(isRoleDefinitionDirty(base, { ...base, permissionIds: [2, 1] })).toBe(false)
  })
  it('1項目の変更はtrue', () => {
    expect(isRoleDefinitionDirty(base, { ...base, name: '運用者' })).toBe(true)
    expect(isRoleDefinitionDirty(base, { ...base, permissionIds: [1] })).toBe(true)
    expect(isRoleDefinitionDirty(base, { ...base, featureIds: [] })).toBe(true)
  })
  it('元に戻すとfalse', () => {
    const changed = { ...base, description: 'x' }
    expect(isRoleDefinitionDirty(base, { ...changed, description: '' })).toBe(false)
  })
})
