import { useCancelCompensatoryLeaveRequest } from '../../hooks/useCompensatoryLeave'
import { useCancelPaidLeaveRequest } from '../../hooks/usePaidLeave'
import { useCancelSpecialLeaveRequest } from '../../hooks/useSpecialLeave'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../ui/dialog'

export type ApprovedLeaveKind = 'paid' | 'special' | 'compensatory'

export interface ApprovedLeaveTarget {
  kind: ApprovedLeaveKind
  id: string
  label: string
}

/**
 * 承認済みの休暇(有給・特別休暇・代休)の承認を取り消す確認ダイアログ。取消により、
 * 消化済みの残数が戻り、対象日の勤怠区分(work_type)もクリアされる
 * (Cancel{PaidLeave,SpecialLeave,CompensatoryLeave}RequestHandler参照)。月次勤怠が
 * 既に確定済みの場合はAPI側で拒否される。日次勤怠画面のケバブメニューと、週次・月次
 * 画面の各日行のケバブメニューの両方から使う(承認済み休暇の取消操作を1箇所にまとめる)。
 */
export function CancelApprovedLeaveDialog({
  target,
  onOpenChange,
  onCancelled,
}: {
  target: ApprovedLeaveTarget | null
  onOpenChange: (open: boolean) => void
  onCancelled: () => void
}) {
  const cancelPaidLeave = useCancelPaidLeaveRequest()
  const cancelSpecialLeave = useCancelSpecialLeaveRequest()
  const cancelCompensatoryLeave = useCancelCompensatoryLeaveRequest()

  const mutation =
    target?.kind === 'special' ? cancelSpecialLeave : target?.kind === 'compensatory' ? cancelCompensatoryLeave : cancelPaidLeave

  return (
    <Dialog
      open={target !== null}
      onOpenChange={(open) => {
        if (!open) {
          cancelPaidLeave.reset()
          cancelSpecialLeave.reset()
          cancelCompensatoryLeave.reset()
        }
        onOpenChange(open)
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{target?.label}の承認を取り消しますか?</DialogTitle>
          <DialogDescription>
            消化済みの残数が戻り、この日の勤怠区分もクリアされます。月次勤怠が既に確定済みの場合は取り消せません。
          </DialogDescription>
        </DialogHeader>
        {mutation.error && <ErrorMessage error={mutation.error} />}
        <DialogFooter>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            キャンセル
          </Button>
          <Button
            variant="danger"
            isLoading={mutation.isPending}
            onClick={() => {
              if (!target) return
              mutation.mutate(target.id, { onSuccess: () => onCancelled() })
            }}
          >
            取り消す
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
