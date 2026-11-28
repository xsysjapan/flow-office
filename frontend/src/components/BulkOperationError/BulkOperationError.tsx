import { CircleAlert } from 'lucide-react'
import { ApiError } from '../../api/client'
import { BULK_REJECTED_NOTICE } from '../BulkActionDialog/BulkActionDialog'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { Alert, AlertDescription } from '../ui/alert'

export interface BulkOperationErrorProps {
  error: unknown
  /** 失敗した対象の表示名(ID→表示名)。応答に表示名が無い対象に使う。 */
  labels?: Record<string, string>
  /** 一括操作の拒否(`bulk_operation_rejected`)以外のエラーで、メッセージが無いときの文言。 */
  fallback?: string
}

/**
 * 全部か無しかの一括操作のエラー表示(確認ダイアログを使わない画面用)。`ApiError`の
 * `code === 'bulk_operation_rejected'`なら、何も処理していないことを示す文と、失敗した対象・理由の一覧を出す
 * (選択は呼び出し側がそのまま残す)。それ以外は`ErrorMessage`と同じ表示にする。
 */
export function BulkOperationError({ error, labels, fallback }: BulkOperationErrorProps) {
  if (!(error instanceof ApiError) || error.code !== 'bulk_operation_rejected') {
    return <ErrorMessage error={error} fallback={fallback} />
  }

  return (
    <Alert variant="destructive" className="mb-4">
      <CircleAlert />
      <AlertDescription>
        <p>{BULK_REJECTED_NOTICE}</p>
        <ul className="list-disc pl-4">
          {(error.items ?? []).map((item, index) => (
            <li key={`${item.id}-${index}`}>
              {item.label ?? labels?.[item.id] ?? item.id}: {item.message}
            </li>
          ))}
        </ul>
      </AlertDescription>
    </Alert>
  )
}
