export interface SingleExpenseItemFormValues {
  usageDate: string
  amount: string
  payee: string
  content: string
  participants: string
  participantCount: string
  departure: string
  destination: string
  paymentBearer: string
  attributeValues: Record<string, string | boolean>
  hasReceiptFile: boolean
}

export const SINGLE_EXPENSE_ITEM_FORM_INITIAL: SingleExpenseItemFormValues = {
  usageDate: '',
  amount: '',
  payee: '',
  content: '',
  participants: '',
  participantCount: '',
  departure: '',
  destination: '',
  paymentBearer: 'employee',
  attributeValues: {},
  hasReceiptFile: false,
}

/** 追加入力項目は、空文字・falseを「未入力」として扱う。 */
function filledAttributes(values: Record<string, string | boolean>): string {
  return JSON.stringify(
    Object.entries(values)
      .filter(([, value]) => value !== '' && value !== false)
      .sort(([a], [b]) => a.localeCompare(b)),
  )
}

/** 単票入力フォーム: 初期値と今の入力を比べる(保存して続けて入力すると初期値に戻る)。 */
export function isSingleExpenseItemFormDirty(
  base: SingleExpenseItemFormValues,
  current: SingleExpenseItemFormValues,
): boolean {
  return (
    base.usageDate !== current.usageDate ||
    base.amount !== current.amount ||
    base.payee !== current.payee ||
    base.content !== current.content ||
    base.participants !== current.participants ||
    base.participantCount !== current.participantCount ||
    base.departure !== current.departure ||
    base.destination !== current.destination ||
    base.paymentBearer !== current.paymentBearer ||
    base.hasReceiptFile !== current.hasReceiptFile ||
    filledAttributes(base.attributeValues) !== filledAttributes(current.attributeValues)
  )
}
