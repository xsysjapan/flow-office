import { useState } from 'react'
import { Button } from '../Button/Button'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'

export interface RevokeGrantButtonProps {
  id: string
  title: string
  description: string
  onRevoke: (reason?: string) => Promise<unknown>
  isPending?: boolean
  error?: unknown
  /** trueの場合、確認ダイアログを開かず理由不要でボタンをdisabled表示する(既に取消済み等)。 */
  disabled?: boolean
  /** disabled時にボタンへ添えるツールチップ文言(押せない理由)。 */
  disabledReason?: string
}

/**
 * 有給/特別休暇/代休の付与取消で共通利用する破壊的操作ボタン。任意の取消理由を入力して
 * `ConfirmActionDialog`で確認したうえで取り消す。
 */
export function RevokeGrantButton({
  id,
  title,
  description,
  onRevoke,
  isPending = false,
  error,
  disabled = false,
  disabledReason,
}: RevokeGrantButtonProps) {
  const [reason, setReason] = useState('')

  if (disabled) {
    return (
      <Button variant="danger" size="sm" disabled title={disabledReason}>
        取消
      </Button>
    )
  }

  return (
    <ConfirmActionDialog
      triggerLabel="取消"
      title={title}
      description={description}
      confirmLabel="取消する"
      isPending={isPending}
      error={error}
      onConfirm={() => onRevoke(reason || undefined)}
      onOpenChange={(open) => {
        if (open) setReason('')
      }}
    >
      <FormField label="取消理由(任意)" htmlFor={id}>
        <Input id={id} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </ConfirmActionDialog>
  )
}
