import { ChevronDown } from 'lucide-react'
import { Button, type ButtonVariant } from '../Button/Button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '../ui/dropdown-menu'

export interface BulkAction {
  key: string
  label: string
  onClick: () => void
  disabled?: boolean
  /** disabledのとき、ボタンの近くに表示する理由(§2.14)。 */
  disabledReason?: string
  variant?: ButtonVariant
}

export interface BulkActionBarProps {
  selectedCount: number
  onClear: () => void
  actions: BulkAction[]
}

/** バーに直接並べる操作の最大数。超える分は最後の枠(「その他」メニュー)にまとめる。 */
const MAX_VISIBLE_ACTIONS = 4

/**
 * 一覧の複数選択時だけ表に接して出す一括操作バー(ui-interaction-patterns §2.21)。
 * 「n件を選択中」・操作ボタン・「選択を解除」を並べる。ボタンはダイアログを開くだけで、バー内に入力欄は置かない。
 * PC(sm以上)は1行、モバイルは1行目に件数と選択解除、2行目に横スクロールの操作列を置く。
 * `Card`のactionsスロットには渡さず、Card本文の先頭に1つだけ置く。
 */
export function BulkActionBar({ selectedCount, onClear, actions }: BulkActionBarProps) {
  if (selectedCount === 0) return null

  const needsMenu = actions.length > MAX_VISIBLE_ACTIONS
  const visibleActions = needsMenu ? actions.slice(0, MAX_VISIBLE_ACTIONS - 1) : actions
  const menuActions = needsMenu ? actions.slice(MAX_VISIBLE_ACTIONS - 1) : []
  const disabledReasons = actions.filter((action) => action.disabled && action.disabledReason)

  return (
    <div className="mb-4 flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
      <div className="flex items-center justify-between gap-2 sm:contents">
        <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedCount}件を選択中</span>
        <Button variant="secondary" size="sm" className="sm:order-last" onClick={onClear}>
          選択を解除
        </Button>
      </div>
      <div className="-mx-1 flex items-center gap-2 overflow-x-auto px-1 sm:mx-0 sm:overflow-visible sm:px-0">
        {visibleActions.map((action) => (
          <Button
            key={action.key}
            size="sm"
            variant={action.variant}
            className="shrink-0"
            disabled={action.disabled}
            onClick={action.onClick}
          >
            {action.label}
          </Button>
        ))}
        {menuActions.length > 0 && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button size="sm" variant="secondary" className="shrink-0">
                その他
                <ChevronDown aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              {menuActions.map((action) => (
                <DropdownMenuItem
                  key={action.key}
                  disabled={action.disabled}
                  variant={action.variant === 'danger' ? 'destructive' : 'default'}
                  onSelect={action.onClick}
                >
                  {action.label}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>
      {disabledReasons.length > 0 && (
        <div className="flex flex-col text-xs text-muted-foreground">
          {disabledReasons.map((action) => (
            <p key={action.key}>{action.disabledReason}</p>
          ))}
        </div>
      )}
    </div>
  )
}
