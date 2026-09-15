import { Button } from '../Button/Button'

export interface PaidLeaveScheduleBulkGrantBarProps {
  /** 選択中の件数(Eligible行のみ選択可能)。 */
  selectedCount: number
  isSubmitting?: boolean
  onCancel: () => void
  onBulkGrant: () => void
}

/**
 * 付与予定一覧の一括付与バー(spec.md論点12・15-6)。選択があるときだけ表示する
 * (ui-interaction-patterns §2.21)。モバイル幅(`sm`未満)では「件数+キャンセル」を1行目、
 * 「一括付与」ボタンを2行目に分ける`AttendanceSelectionActionBar`と同じレイアウトパターンを
 * 踏襲する(ボタン数はこの画面では1つのみだが、Card内での崩れを避けるため同じ構成にする)。
 */
export function PaidLeaveScheduleBulkGrantBar({
  selectedCount,
  isSubmitting = false,
  onCancel,
  onBulkGrant,
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
        <Button size="sm" className="shrink-0" isLoading={isSubmitting} onClick={onBulkGrant}>
          一括付与する
        </Button>
      </div>
    </div>
  )
}
