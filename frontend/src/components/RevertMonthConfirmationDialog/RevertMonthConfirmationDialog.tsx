import { useState } from 'react'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'
import { FormField } from '../FormField/FormField'
import { Textarea } from '../ui/textarea'
import { useRevertMonthConfirmation } from '../../hooks/useAttendance'

export interface RevertMonthConfirmationDialogProps {
  monthId: string
  yearMonth: string
}

/**
 * 承認済みの月次勤怠を、申請なしで未提出に戻す確認ダイアログ(バックオフィス担当者の直接取消)。
 * 入力したメッセージは社員の月次勤怠画面に表示され、通知でも送られる。
 * 締め処理タスク詳細と勤怠参照画面の両方から使うため、ReopenMonthDialogと同じ構成で部品にした。
 */
export function RevertMonthConfirmationDialog({ monthId, yearMonth }: RevertMonthConfirmationDialogProps) {
  const [message, setMessage] = useState('')
  const [validationError, setValidationError] = useState<string | undefined>(undefined)
  const revertMonth = useRevertMonthConfirmation()

  return (
    <ConfirmActionDialog
      triggerLabel="未提出に戻す"
      triggerVariant="secondary"
      title="月次勤怠を未提出に戻しますか?"
      description={`${yearMonth}の月次勤怠を未提出に戻します。社員は日次実績を編集して再提出できるようになります。入力したメッセージは社員の月次勤怠画面に表示され、通知でも送られます。`}
      confirmLabel="未提出に戻す"
      isPending={revertMonth.isPending}
      error={revertMonth.error}
      onOpenChange={(open) => {
        if (open) {
          setMessage('')
          setValidationError(undefined)
          revertMonth.reset()
        }
      }}
      onConfirm={() => {
        if (message.trim() === '') {
          setValidationError('社員へのメッセージを入力してください。')
          return
        }
        setValidationError(undefined)
        return revertMonth.mutateAsync({ id: monthId, reason: message })
      }}
    >
      <FormField label="社員へのメッセージ" htmlFor="revert-month-message" required error={validationError}>
        <Textarea id="revert-month-message" value={message} onChange={(e) => setMessage(e.target.value)} rows={3} />
      </FormField>
    </ConfirmActionDialog>
  )
}
