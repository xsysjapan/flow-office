import type { ExpenseCategory } from '../api/types'

/** UC-X004a〜d: 経費区分ごとの単発入力フォームの入力項目セット。プリセット編集画面
 *  (`ExpenseEntryPresetEditPage`)も、実際の入力フォーム(`SingleExpenseItemForm`)と
 *  同じ入力補助欄を出すため、この判定をfront全体で共有する。 */
export type SingleExpenseItemFieldSet = 'meal' | 'lodging' | 'generic' | 'other' | 'transport'

/** 区分コードから単発入力フォームの`fieldSet`を決める。会食・宿泊・交通費・その他以外は
 *  すべて汎用(取引先必須+内容)の`generic`にまとめ、区分が増えてもフロント分岐を増やさない。
 *  「その他」は取引先が無い経費(郵送料の実費精算等)もあり得るため、取引先を任意項目にした
 *  専用の`other`を使う。 */
export function fieldSetForCategory(category: Pick<ExpenseCategory, 'code'>): SingleExpenseItemFieldSet {
  if (category.code === 'transportation') return 'transport'
  if (category.code === 'meal') return 'meal'
  if (category.code === 'lodging') return 'lodging'
  if (category.code === 'other') return 'other'
  return 'generic'
}

/** 出発地/到着地の2項目から`description`用の1行テキストを組み立てる
 *  (docs/30-usecases-expense.md UC-X004a: 「出発地 → 到着地」の所定フォーマット)。 */
export function composeRouteDescription(departure: string, destination: string): string {
  if (!departure && !destination) return ''
  return `${departure} → ${destination}`
}

/** 単発入力フォーム・プリセット編集画面で共通して使う、fieldSetごとの入力補助欄の値。 */
export interface ExpenseItemAssistFields {
  payee?: string
  content?: string
  participants?: string
  participantCount?: string
  departure?: string
  destination?: string
}

/** 入力補助欄の値からdescription(保存用の1行テキスト)を組み立てる。SingleExpenseItemForm
 *  の入力画面とプリセット編集画面(初回の入力補助の下書き)で同じ整形ルールを使うための
 *  共通ロジック。 */
export function buildExpenseItemDescription(
  fieldSet: SingleExpenseItemFieldSet,
  fields: ExpenseItemAssistFields,
): string | undefined {
  const payee = fields.payee ?? ''
  const content = fields.content ?? ''

  if (fieldSet === 'meal') {
    return `${payee} - ${content} (${fields.participantCount ?? ''}名: ${fields.participants ?? ''})`
  }
  if (fieldSet === 'other') {
    if (payee && content) return `${payee} - ${content}`
    return payee || content || undefined
  }
  if (fieldSet === 'transport') {
    return content || undefined
  }
  if (content) return `${payee} - ${content}`
  return payee || undefined
}
