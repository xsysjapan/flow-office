import { useEffect, useState, type ReactNode } from 'react'
import { ApiError } from '../../api/client'
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

export const BULK_REJECTED_NOTICE =
  '選んだ対象のうち、処理できないものがあります。何も処理していません。対象を選び直してください。'

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
  /**
   * 結果の形。`partial`(既定)は件ごとの部分成功。`allOrNothing`は一括APIを1回呼ぶ形で、`onExecute`が
   * 返れば全件成功として閉じる(`failures`は使わない)。`ApiError`の`code === 'bulk_operation_rejected'`なら
   * 失敗した対象と理由の一覧を出し、選択は残す(`onSucceeded`を呼ばない)。それ以外のエラーは通常のエラー表示。
   */
  resultMode?: 'partial' | 'allOrNothing'
  /** `allOrNothing`で、応答に表示名が無い対象の表示名(ID→表示名)。 */
  itemLabels?: Record<string, string>
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
  resultMode = 'partial',
  itemLabels,
}: BulkActionDialogProps) {
  const [isRunning, setIsRunning] = useState(false)
  const [result, setResult] = useState<BulkActionResult | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [rejected, setRejected] = useState<ApiError | null>(null)

  // 開き直すたびに前回の結果・エラーを消す
  useEffect(() => {
    if (open) {
      setResult(null)
      setError(null)
      setRejected(null)
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
      if (resultMode === 'allOrNothing') {
        onSucceeded(executed.succeededIds)
        if (executed.succeededIds.length > 0) notify.success(successMessage(executed.succeededIds.length))
        onOpenChange(false)
        return
      }
      if (executed.succeededIds.length > 0) onSucceeded(executed.succeededIds)
      if (executed.failures.length === 0) {
        if (executed.succeededIds.length > 0) notify.success(successMessage(executed.succeededIds.length))
        onOpenChange(false)
      } else {
        setResult(executed)
      }
    } catch (e) {
      if (resultMode === 'allOrNothing' && e instanceof ApiError && e.code === 'bulk_operation_rejected') {
        setRejected(e)
      } else {
        setError(e)
      }
    } finally {
      setIsRunning(false)
    }
  }

  if (rejected) {
    return (
      <ConfirmActionDialog
        open={open}
        onOpenChange={handleOpenChange}
        title={resultTitle}
        description={BULK_REJECTED_NOTICE}
        confirmLabel="閉じる"
        confirmVariant="secondary"
        hideCancel
        onConfirm={() => handleOpenChange(false)}
      >
        <ul className="list-disc pl-5 text-sm text-foreground">
          {(rejected.items ?? []).map((item, index) => (
            <li key={`${item.id}-${index}`}>
              {item.label ?? itemLabels?.[item.id] ?? item.id}: {item.message}
            </li>
          ))}
        </ul>
      </ConfirmActionDialog>
    )
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
