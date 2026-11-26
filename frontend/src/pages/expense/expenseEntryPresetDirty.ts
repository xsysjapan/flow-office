export interface ExpenseEntryPresetFormValues {
  visibility: string
  name: string
  description: string
  presetType: string
  items: Array<Record<string, unknown>>
  isActive: boolean
}

export const EXPENSE_ENTRY_PRESET_INITIAL: ExpenseEntryPresetFormValues = {
  visibility: 'personal',
  name: '',
  description: '',
  presetType: 'single_item',
  items: [{ category_id: 0, amount: null, payment_bearer: null }],
  isActive: true,
}

/** 行の比較用。rowId(画面内の連番)を除き、null・未設定・空文字を同じ「空」として扱う。 */
function normalizeItem(item: Record<string, unknown>): string {
  const entries = Object.entries(item)
    .filter(([key, value]) => key !== 'rowId' && value !== null && value !== undefined && value !== '')
    .sort(([a], [b]) => a.localeCompare(b))
  return JSON.stringify(entries)
}

/** 経費入力プリセットの新規・編集: 比べる元(新規は初期値、編集は読み込んだ値)と今の入力を比べる。 */
export function isExpenseEntryPresetDirty(
  base: ExpenseEntryPresetFormValues,
  current: ExpenseEntryPresetFormValues,
): boolean {
  return (
    base.visibility !== current.visibility ||
    base.name !== current.name ||
    base.description !== current.description ||
    base.presetType !== current.presetType ||
    base.isActive !== current.isActive ||
    base.items.length !== current.items.length ||
    base.items.some((item, i) => normalizeItem(item) !== normalizeItem(current.items[i]))
  )
}
