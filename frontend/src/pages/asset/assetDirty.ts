export interface AssetEditValues {
  name: string
  category: string
  serialNumber: string
  notes: string
}

export interface AssetRegisterValues extends AssetEditValues {
  assetNo: string
  managementType: string
  lendingMethod: string
  defaultLocationText: string
}

export const ASSET_REGISTER_INITIAL: AssetRegisterValues = {
  assetNo: '',
  name: '',
  category: '',
  serialNumber: '',
  managementType: 'lending',
  lendingMethod: 'backoffice',
  defaultLocationText: '',
  notes: '',
}

/** 備品の編集: 読み込んだ値と今の入力を比べる。 */
export function isAssetEditDirty(base: AssetEditValues, current: AssetEditValues): boolean {
  return (
    base.name !== current.name ||
    base.category !== current.category ||
    base.serialNumber !== current.serialNumber ||
    base.notes !== current.notes
  )
}

/** 備品の登録: 初期値と今の入力を比べる。 */
export function isAssetRegisterDirty(base: AssetRegisterValues, current: AssetRegisterValues): boolean {
  return (
    isAssetEditDirty(base, current) ||
    base.assetNo !== current.assetNo ||
    base.managementType !== current.managementType ||
    base.lendingMethod !== current.lendingMethod ||
    base.defaultLocationText !== current.defaultLocationText
  )
}
