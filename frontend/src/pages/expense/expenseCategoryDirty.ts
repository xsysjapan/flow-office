import type { ExpenseCategoryFieldDefinition } from '../../api/types'

export interface ExpenseCategoryFormValues {
  code: string
  name: string
  description: string
  evidenceTypeDefault: string
  entryMode: string
  fieldDefinitions: ExpenseCategoryFieldDefinition[]
  receiptRequiredThreshold: string
  approvalSkipThreshold: string
  isActive: boolean
}

export const EXPENSE_CATEGORY_INITIAL: ExpenseCategoryFormValues = {
  code: '',
  name: '',
  description: '',
  evidenceTypeDefault: 'fact_reference_available',
  entryMode: 'single',
  fieldDefinitions: [],
  receiptRequiredThreshold: '',
  approvalSkipThreshold: '',
  isActive: true,
}

function sameFields(a: ExpenseCategoryFieldDefinition[], b: ExpenseCategoryFieldDefinition[]): boolean {
  if (a.length !== b.length) return false
  return a.every((field, index) => {
    const other = b[index]
    return (
      field.key === other.key &&
      field.label === other.label &&
      field.type === other.type &&
      (field.required ?? false) === (other.required ?? false) &&
      JSON.stringify(field.options ?? null) === JSON.stringify(other.options ?? null)
    )
  })
}

/** 経費区分の新規・編集: 比べる元(新規は初期値、編集は読み込んだ値)と今の入力を比べる。 */
export function isExpenseCategoryDirty(base: ExpenseCategoryFormValues, current: ExpenseCategoryFormValues): boolean {
  return (
    base.code !== current.code ||
    base.name !== current.name ||
    base.description !== current.description ||
    base.evidenceTypeDefault !== current.evidenceTypeDefault ||
    base.entryMode !== current.entryMode ||
    base.receiptRequiredThreshold !== current.receiptRequiredThreshold ||
    base.approvalSkipThreshold !== current.approvalSkipThreshold ||
    base.isActive !== current.isActive ||
    !sameFields(base.fieldDefinitions, current.fieldDefinitions)
  )
}
