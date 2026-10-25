import { useState } from 'react'
import { Button } from '../Button/Button'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'

export interface ChangeGrantDaysButtonProps {
  id: string
  /** 付与日(説明文に出す)。 */
  grantedOn: string
  /** 現在の付与日数(入力の初期値)。 */
  currentDays: number
  /** 使用済みの日数。これより少なくはできない(0.5単位に切り上げて最小値にする)。 */
  usedDays: number
  onChange: (grantedDays: number, reason?: string) => Promise<unknown>
  isPending?: boolean
  error?: unknown
  /** trueの場合、確認ダイアログを開かずボタンをdisabled表示する(最新でない付与、代休など)。 */
  disabled?: boolean
  /** disabled時にボタンへ添えるツールチップ文言(押せない理由)。 */
  disabledReason?: string
}

/** 新しい付与日数の最小値: 使用済みの日数を0.5単位に切り上げた値と0.5の大きい方。 */
function minGrantDays(usedDays: number): number {
  return Math.max(0.5, Math.ceil(usedDays * 2 - 1e-9) / 2)
}

/**
 * 有給/特別休暇/代休の付与一覧で共通利用する「付与日数を変更」ボタン。`RevokeGrantButton`と同じ作りで、
 * 新しい付与日数と任意の理由を入力して`ConfirmActionDialog`で確定する。
 */
export function ChangeGrantDaysButton({
  id,
  grantedOn,
  currentDays,
  usedDays,
  onChange,
  isPending = false,
  error,
  disabled = false,
  disabledReason,
}: ChangeGrantDaysButtonProps) {
  const [days, setDays] = useState(String(currentDays))
  const [reason, setReason] = useState('')
  const min = minGrantDays(usedDays)
  const parsed = days.trim() === '' ? Number.NaN : Number(days)
  const invalid = Number.isNaN(parsed) || parsed < min

  if (disabled) {
    return (
      <Button variant="secondary" size="sm" disabled title={disabledReason}>
        日数を変更
      </Button>
    )
  }

  return (
    <ConfirmActionDialog
      triggerLabel="日数を変更"
      triggerVariant="secondary"
      title="付与日数を変更しますか?"
      description={`付与日: ${grantedOn} / 現在の付与日数: ${currentDays}日。申請中の休暇があると、確定時に残日数が足りなくなることがあります。`}
      confirmLabel="変更する"
      confirmVariant="primary"
      confirmDisabled={invalid}
      isPending={isPending}
      error={error}
      onConfirm={() => onChange(parsed, reason || undefined)}
      onOpenChange={(open) => {
        if (open) {
          setDays(String(currentDays))
          setReason('')
        }
      }}
    >
      <FormField label="新しい付与日数" htmlFor={`${id}-days`} required>
        <Input
          id={`${id}-days`}
          type="number"
          step={0.5}
          min={min}
          value={days}
          onChange={(e) => setDays(e.target.value)}
        />
      </FormField>
      <FormField label="変更理由(任意)" htmlFor={`${id}-reason`}>
        <Input id={`${id}-reason`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </ConfirmActionDialog>
  )
}
