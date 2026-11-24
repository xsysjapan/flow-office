import type { ReactNode } from 'react'

export interface FormActionsProps {
  /** [キャンセル][その他(下書き保存など)][主ボタン] の順で渡す。 */
  children: ReactNode
}

/**
 * フォーム末尾のボタン行。右寄せで、狭い幅では縦に積み主ボタンが上に来る。
 * コンテナクエリ(@sm:)を使うため、必ず FormLayout の中に置くこと。
 */
export function FormActions({ children }: FormActionsProps) {
  return <div className="flex flex-col-reverse gap-2 pt-2 @sm:flex-row @sm:justify-end">{children}</div>
}
