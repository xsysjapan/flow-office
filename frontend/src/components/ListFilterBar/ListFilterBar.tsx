import { ChevronDown, ChevronUp } from 'lucide-react'
import { useId, useState } from 'react'
import type { ReactNode } from 'react'
import { Button } from '../ui/button'
import { cn } from '../../lib/utils'

// 子として直接置かれた FormField の下マージンを打ち消す(項目間の間隔は gap で取る)。
const barClassName = 'flex flex-wrap items-end gap-x-4 gap-y-3 [&>*]:mb-0'
// 詳細条件の面。中は等幅のグリッドで、項目の幅はグリッドに任せる。
const advancedPanelClassName =
  'grid grid-cols-1 items-end gap-3 rounded-md border bg-muted/40 p-3 @sm:grid-cols-2 @3xl:grid-cols-3 [&>*]:mb-0'

export interface ListFilterBarProps {
  /** 直接表示する項目。 */
  children: ReactNode
  /** 詳細条件の項目。 */
  advanced?: ReactNode
  /** 詳細条件のうち既定以外の値が入っている数。 */
  advancedActiveCount?: number
  isFiltered: boolean
  onClear: () => void
  className?: string
}

export function ListFilterBar({
  children,
  advanced,
  advancedActiveCount = 0,
  isFiltered,
  onClear,
  className,
}: ListFilterBarProps) {
  const [open, setOpen] = useState(advancedActiveCount > 0)
  const panelId = useId()
  const hasAdvanced = advanced !== undefined && advanced !== null && advanced !== false

  return (
    <div className={cn('@container flex flex-col gap-3', className)}>
      <div className={barClassName}>
        {children}
        {(hasAdvanced || isFiltered) && (
          <div className="ml-auto flex gap-2">
            {hasAdvanced && (
              <Button
                variant="secondary"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((prev) => !prev)}
              >
                {advancedActiveCount > 0 ? `詳細条件(${advancedActiveCount})` : '詳細条件'}
                {open ? <ChevronUp aria-hidden="true" /> : <ChevronDown aria-hidden="true" />}
              </Button>
            )}
            {isFiltered && (
              <Button variant="secondary" onClick={onClear}>
                検索条件をクリア
              </Button>
            )}
          </div>
        )}
      </div>
      {hasAdvanced && open && (
        <div id={panelId} className={advancedPanelClassName}>
          {advanced}
        </div>
      )}
    </div>
  )
}
