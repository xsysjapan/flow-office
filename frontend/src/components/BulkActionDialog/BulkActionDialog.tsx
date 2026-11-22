import { useEffect, useState, type ReactNode } from 'react'
import { notify } from '../../lib/notify'
import { ConfirmActionDialog } from '../ConfirmActionDialog/ConfirmActionDialog'

export interface BulkActionFailure {
  id: string
  /** 失敗した対象の表示名(タスクのタイトルなど)。 */
  label: string
  /** 利用者向けの理由(`ApiError`のメッセージ)。内部例外の文言は渡さない。 */
  message: string
}

export interface BulkActionResult {
  succeededIds: string[]
  failures: BulkActionFailure[]
}

export interface BulkActionDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** 「〜しますか?」の形。 */
  title: string
  /** 対象の要約(「選択した3件」、内訳、変更不要の件数など)。インライン要素で渡す。 */
  summary: ReactNode
  /** 操作の結果の説明。 */
  description?: ReactNode
  /** 入力欄など。 */
  children?: ReactNode
  /** 「3件を〇〇」の形。 */
  confirmLabel: string
  confirmDisabled?: boolean
  /** 一部失敗の結果表示のタイトル。省略時は「実行結果」。 */
  resultTitle?: string
  /**
   * 実行する。1件ずつの結果を`succeededIds`/`failures`に詰めて返す。全体成功/全体失敗のAPIでは、
   * 失敗時に例外を投げる(ダイアログは開いたままErrorMessageを出し、選択も残る)。
   */
  onExecute: () => Promise<BulkActionResult>
  /** 成功した対象のID。呼び出し側が選択から外す。一部失敗でも成功分だけ即座に呼ばれる。 */
  onSucceeded: (succeededIds: string[]) => void
  /** 成功トーストの文言。 */
  successMessage: (count: number) => string
}

/**
 * 一括操作の確認・入力・結果表示を行うダイアログ(`ConfirmActionDialog`の制御モードの上に組む)。
 * 実行中は閉じられない。全件成功なら閉じて成功トースト、一部失敗なら開いたまま内訳を出し「閉じる」だけにする
 * (閉じたとき成功が1件以上ならトースト)。例外は開いたままErrorMessageで出す。
 */
export function BulkActionDialog({
  open,
  onOpenChange,
  title,
  summary,
  description,
  children,
  confirmLabel,
  confirmDisabled = false,
  resultTitle = '実行結果',
  onExecute,
  onSucceeded,
  successMessage,
}: BulkActionDialogProps) {
  const [isRunning, setIsRunning] = useState(false)
  const [result, setResult] = useState<BulkActionResult | null>(null)
  const [error, setError] = useState<unknown>(null)

  // 開き直すたびに前回の結果・エラーを消す
  useEffect(() => {
    if (open) {
      setResult(null)
      setError(null)
    }
  }, [open])

  const handleOpenChange = (next: boolean) => {
    if (isRunning) return
    if (!next && result && result.succeededIds.length > 0) {
      notify.success(successMessage(result.succeededIds.length))
    }
    onOpenChange(next)
  }

  const handleExecute = async () => {
    setError(null)
    setIsRunning(true)
    try {
      const executed = await onExecute()
      if (executed.succeededIds.length > 0) onSucceeded(executed.succeededIds)
      if (executed.failures.length === 0) {
        if (executed.succeededIds.length > 0) notify.success(successMessage(executed.succeededIds.length))
        onOpenChange(false)
      } else {
        setResult(executed)
      }
    } catch (e) {
      setError(e)
    } finally {
      setIsRunning(false)
    }
  }

  if (result) {
    const count = result.succeededIds.length
    const failureCount = result.failures.length
    return (
      <ConfirmActionDialog
        open={open}
        onOpenChange={handleOpenChange}
        title={resultTitle}
        description={
          count > 0
            ? `${successMessage(count)}。${failureCount}件はできませんでした。`
            : `${failureCount}件ともできませんでした。`
        }
        confirmLabel="閉じる"
        confirmVariant="secondary"
        hideCancel
        onConfirm={() => handleOpenChange(false)}
      >
        <ul className="list-disc pl-5 text-sm text-foreground">
          {result.failures.map((failure) => (
            <li key={failure.id}>
              {failure.label}: {failure.message}
            </li>
          ))}
        </ul>
        <p className="text-sm text-muted-foreground">できなかった{failureCount}件は選択したままです。</p>
      </ConfirmActionDialog>
    )
  }

  return (
    <ConfirmActionDialog
      open={open}
      onOpenChange={handleOpenChange}
      title={title}
      description={summary}
      confirmLabel={confirmLabel}
      confirmVariant="primary"
      confirmDisabled={confirmDisabled}
      isPending={isRunning}
      cancelDisabled={isRunning}
      error={error ?? undefined}
      onConfirm={() => void handleExecute()}
    >
      {children}
      {description && <p className="text-sm text-muted-foreground">{description}</p>}
    </ConfirmActionDialog>
  )
}
