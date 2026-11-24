import type { ReactNode } from 'react'
import { cn } from '../../lib/utils'

export interface FormLayoutProps {
  children: ReactNode
  className?: string
}

/**
 * フォームの項目・セクション・ボタン行を置く幅の枠(最大 max-w-2xl)。
 * 自分の幅を基準にするコンテナクエリの起点(@container)でもある。Dialog・Sheet の中では使わない。
 * 縦の間隔は FormField の mb-4 に任せ、gap は足さない。
 */
export function FormLayout({ children, className }: FormLayoutProps) {
  return <div className={cn('@container flex w-full max-w-2xl flex-col', className)}>{children}</div>
}
