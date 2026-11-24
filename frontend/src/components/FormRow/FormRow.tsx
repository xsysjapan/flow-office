import type { ReactNode } from 'react'
import { cn } from '../../lib/utils'

export interface FormRowProps {
  /** 横に並べる列数(狭い幅では1列)。既定は2。 */
  columns?: 2 | 4
  children: ReactNode
}

/** 組で読む短い項目(期間の開始と終了など)を横に並べる。自分の幅で列数を決めるので、どの器でも同じに動く。 */
export function FormRow({ columns = 2, children }: FormRowProps) {
  return (
    <div className="@container">
      <div className={cn('grid grid-cols-1 gap-x-4 @sm:grid-cols-2', columns === 4 && '@xl:grid-cols-4')}>{children}</div>
    </div>
  )
}
