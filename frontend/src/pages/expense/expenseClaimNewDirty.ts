import type { SaveExpenseItemInput } from '../../api/expenseClaims'

/** 経費の申請画面で、まだ保存されていない入力(保存済みの明細・即時に保存される操作は含まない)。 */
export interface ExpenseClaimNewDraft {
  /** 表形式入力の、まだ保存していない行 */
  rows: SaveExpenseItemInput[]
  /** 表形式の行ごとに選んだ領収書の数 */
  selectedFileCount: number
  /** 「まとめて経費登録」のタイトル入力(未確定の文字列) */
  bulkTitle: string
  /** 申請する承認者の選択 */
  approverUserId: string | undefined
}

export const EXPENSE_CLAIM_NEW_INITIAL: ExpenseClaimNewDraft = {
  rows: [],
  selectedFileCount: 0,
  bulkTitle: '',
  approverUserId: undefined,
}

/** 区分だけを入れた空の行(「行を追加」直後)は、入力が無いものとして扱う。 */
function isFilledRow(row: SaveExpenseItemInput): boolean {
  return (
    row.usage_date !== '' ||
    row.amount !== 0 ||
    Boolean(row.description) ||
    Boolean(row.payment_bearer) ||
    Boolean(row.attributes && Object.keys(row.attributes).length > 0)
  )
}

function normalizeRows(rows: SaveExpenseItemInput[]): string {
  return JSON.stringify(
    rows.filter(isFilledRow).map((row) => {
      const { rowId, ...rest } = row as SaveExpenseItemInput & { rowId?: number }
      void rowId
      return rest
    }),
  )
}

/** 経費の申請: 比べる元(初期値)と今の入力を比べる。 */
export function isExpenseClaimNewDirty(base: ExpenseClaimNewDraft, current: ExpenseClaimNewDraft): boolean {
  return (
    normalizeRows(base.rows) !== normalizeRows(current.rows) ||
    base.selectedFileCount !== current.selectedFileCount ||
    base.bulkTitle !== current.bulkTitle ||
    base.approverUserId !== current.approverUserId
  )
}
