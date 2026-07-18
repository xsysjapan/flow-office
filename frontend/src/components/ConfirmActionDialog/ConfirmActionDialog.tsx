import type { ReactNode } from 'react'
import { useState } from 'react'
import { Button, type ButtonVariant } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../ui/dialog'

export interface ConfirmActionDialogProps {
  /** ダイアログを開くトリガーボタンのラベル。 */
  triggerLabel: string
  triggerVariant?: ButtonVariant
  title: string
  description: ReactNode
  confirmLabel: string
  onConfirm: () => void
  isPending?: boolean
  error?: unknown
  /** ダイアログの開閉時に呼ばれる(開いた際に呼び出し側のフォーム状態・ミューテーションをリセットする用途)。 */
  onOpenChange?: (open: boolean) => void
  /** 説明文と確認/キャンセルボタンの間に表示する追加コンテンツ(理由入力欄など)。 */
  children?: ReactNode
}

/**
 * 破壊的・取り消せない操作(端末の失効、連携の停止、認証キーの無効化など)を実行する前に
 * 確認を挟むダイアログ。個々のページが独自に同じ形の確認ダイアログを実装しないよう、
 * トリガーボタン・確認文・実行ボタンだけを差し替え可能にした共通コンポーネント。
 */
export function ConfirmActionDialog({
  triggerLabel,
  triggerVariant = 'danger',
  title,
  description,
  confirmLabel,
  onConfirm,
  isPending = false,
  error,
  onOpenChange,
  children,
}: ConfirmActionDialogProps) {
  const [isOpen, setIsOpen] = useState(false)

  const handleOpenChange = (open: boolean) => {
    setIsOpen(open)
    onOpenChange?.(open)
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      <Button size="sm" variant={triggerVariant} onClick={() => handleOpenChange(true)}>
        {triggerLabel}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {error !== undefined && error !== null && <ErrorMessage error={error} />}
        {children}
        <DialogFooter>
          <Button variant="secondary" onClick={() => handleOpenChange(false)}>
            キャンセル
          </Button>
          <Button variant="danger" isLoading={isPending} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
