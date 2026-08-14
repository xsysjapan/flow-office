import type { HTMLAttributes, KeyboardEvent } from 'react'
import { TableRow } from '../ui/table'
import { cn } from '../../lib/utils'

export interface ClickableTableRowProps extends HTMLAttributes<HTMLTableRowElement> {
  /** 行クリック(マウス・Enter・Space)で実行する操作。対象オブジェクトの詳細を開く用途を想定する。 */
  onRowClick: () => void
  /** スクリーンリーダー向けの行のaccessible name(例: `${name}の詳細を開く`)。 */
  rowLabel: string
  /** trueの場合、行クリックを無効化しプレーンな`TableRow`として描画する(削除済み行など)。 */
  disabled?: boolean
}

/**
 * 一覧の行クリックで対象オブジェクトの詳細(ページ遷移・Dialog等)を開く際の共通実装
 * (`.claude/skills/ui-interaction-patterns` §2.2)。行全体をクリック可能にしつつ、
 * `role="button"`/`tabIndex`/Enter・Spaceでキーボードからも同じ操作ができるようにする。
 * 行内に個別のButton/Linkを置く場合は、その要素側で`onClick`に`event.stopPropagation()`を
 * 呼び、行クリックとの二重発火を防ぐこと。
 */
export function ClickableTableRow({
  onRowClick,
  rowLabel,
  disabled = false,
  className,
  onKeyDown,
  children,
  ...props
}: ClickableTableRowProps) {
  if (disabled) {
    return (
      <TableRow className={className} {...props}>
        {children}
      </TableRow>
    )
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLTableRowElement>) => {
    onKeyDown?.(event)
    if (event.defaultPrevented) return
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      onRowClick()
    }
  }

  return (
    <TableRow
      className={cn('cursor-pointer', className)}
      role="button"
      tabIndex={0}
      aria-label={rowLabel}
      onClick={onRowClick}
      onKeyDown={handleKeyDown}
      {...props}
    >
      {children}
    </TableRow>
  )
}
