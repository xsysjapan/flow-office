import type { ReactNode } from 'react'
import { Button } from '../Button/Button'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'

export interface PaidLeaveScheduleBulkGrantBarProps {
  /** 選択中の件数(Eligible行のみ選択可能)。 */
  selectedCount: number
  isSubmitting?: boolean
  /** 確認ダイアログの本文(件数・合計日数・付与予定日の範囲・警告・取消できない旨)。 */
  confirmDescription: ReactNode
  /** 直前の一括付与の失敗(確認ダイアログ内に表示する)。 */
  error?: unknown
  onCancel: () => void
  /** 確認ダイアログで確定したときに呼ばれる。Promiseを返すと、成功時にダイアログを閉じる。 */
  onBulkGrant: () => void | Promise<unknown>
  /** 確認ダイアログを開閉したとき(開くときに前回の失敗表示をリセットするため)。 */
  onConfirmOpenChange?: (open: boolean) => void
}

/**
 * 付与予定一覧の一括付与バー(spec.md論点5)。選択があるときだけ表示する
 * (ui-interaction-patterns §2.21)。「一括付与する」は戻せない操作のため
 * `ConfirmActionDialog`で確認してから実行する(ui-interaction-patterns §2.12)。
 * モバイル幅(`sm`未満)では「件数+キャンセル」を1行目、「一括付与」ボタンを2行目に分ける
 * `AttendanceSelectionActionBar`と同じレイアウトパターンを踏襲する。
 */
export function PaidLeaveScheduleBulkGrantBar({
  selectedCount,
  isSubmitting = false,
  confirmDescription,
  error,
  onCancel,
  onBulkGrant,
  onConfirmOpenChange,
}: PaidLeaveScheduleBulkGrantBarProps) {
  return (
    <div className="flex w-full basis-full flex-col gap-2 sm:w-auto sm:basis-auto sm:flex-row sm:items-center">
      <div className="flex items-center justify-between gap-2 sm:contents">
        <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedCount}件を選択中</span>
        <Button variant="secondary" size="sm" className="sm:order-last" onClick={onCancel} disabled={isSubmitting}>
          キャンセル
        </Button>
      </div>
      <div className="-mx-1 flex gap-2 overflow-x-auto px-1 sm:mx-0 sm:overflow-visible sm:px-0">
        <ConfirmActionDialog
          triggerLabel="一括付与する"
          triggerVariant="danger"
          title="付与予定を一括付与しますか?"
          description={confirmDescription}
          confirmLabel="一括付与する"
          isPending={isSubmitting}
          error={error}
          onOpenChange={onConfirmOpenChange}
          onConfirm={onBulkGrant}
        />
      </div>
    </div>
  )
}
