import { useState, type ReactElement } from 'react'
import { Button, type ButtonVariant } from '../Button/Button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '../ui/dialog'

export interface ConfirmDialogProps {
  /** ダイアログを開くトリガー要素(削除ボタンなど)。クリックイベントはこのコンポーネントが差し替える。 */
  trigger: ReactElement
  title: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  confirmVariant?: ButtonVariant
  isConfirming?: boolean
  onConfirm: () => void
}

/** 削除など取り消せない操作の前に確認を挟む共通ダイアログ。「確定する」を押すまで実行しない。 */
export function ConfirmDialog({
  trigger,
  title,
  description,
  confirmLabel = '削除する',
  cancelLabel = 'キャンセル',
  confirmVariant = 'danger',
  isConfirming = false,
  onConfirm,
}: ConfirmDialogProps) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="secondary">{cancelLabel}</Button>
          </DialogClose>
          <Button
            variant={confirmVariant}
            isLoading={isConfirming}
            onClick={() => {
              onConfirm()
              setOpen(false)
            }}
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
