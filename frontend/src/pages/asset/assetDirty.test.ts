import { describe, expect, it } from 'vitest'
import { ASSET_REGISTER_INITIAL, isAssetEditDirty, isAssetRegisterDirty } from './assetDirty'

const editBase = { name: 'ThinkPad', category: 'ノートPC', serialNumber: 'SN-1', notes: '' }

describe('isAssetEditDirty', () => {
  it('is false when nothing changed', () => {
    expect(isAssetEditDirty(editBase, { ...editBase })).toBe(false)
  })
  it('is true when one field changed', () => {
    expect(isAssetEditDirty(editBase, { ...editBase, notes: 'メモ' })).toBe(true)
  })
  it('is false again after reverting', () => {
    const changed = { ...editBase, name: 'X' }
    expect(isAssetEditDirty(editBase, changed)).toBe(true)
    expect(isAssetEditDirty(editBase, { ...changed, name: 'ThinkPad' })).toBe(false)
  })
})

describe('isAssetRegisterDirty', () => {
  it('is false for the initial values', () => {
    expect(isAssetRegisterDirty(ASSET_REGISTER_INITIAL, { ...ASSET_REGISTER_INITIAL })).toBe(false)
  })
  it('is true when one field changed', () => {
    expect(isAssetRegisterDirty(ASSET_REGISTER_INITIAL, { ...ASSET_REGISTER_INITIAL, lendingMethod: 'approval' })).toBe(true)
    expect(isAssetRegisterDirty(ASSET_REGISTER_INITIAL, { ...ASSET_REGISTER_INITIAL, assetNo: 'A' })).toBe(true)
  })
  it('is false again after reverting', () => {
    const changed = { ...ASSET_REGISTER_INITIAL, name: 'X' }
    expect(isAssetRegisterDirty(ASSET_REGISTER_INITIAL, changed)).toBe(true)
    expect(isAssetRegisterDirty(ASSET_REGISTER_INITIAL, { ...changed, name: '' })).toBe(false)
  })
})
