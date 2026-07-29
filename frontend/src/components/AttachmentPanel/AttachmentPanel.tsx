import { useRef } from 'react'
import type { AttachmentOwnerType } from '../../api/attachments'
import { downloadAttachment } from '../../api/attachments'
import { useAttachments, useUploadAttachment } from '../../hooks/useAttachments'
import { formatFileSize } from '../../utils/formatFileSize'
import { cn } from '../../lib/utils'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { LoadingState } from '../LoadingState/LoadingState'

export interface AttachmentPanelProps {
  ownerType: AttachmentOwnerType
  ownerId: string
  /** trueなら添付表示のみでアップロードUIを出さない(承認者の閲覧専用表示等)。省略時false。 */
  readOnly?: boolean
  /** 添付ファイルが1件も無い場合に警告色で表示する(レシート必須の未添付警告用)。省略時false。 */
  required?: boolean
  /** コンパクト表示(テーブル内セルなど、狭い領域用の小さめレイアウト)。省略時false。 */
  compact?: boolean
  className?: string
}

/**
 * 添付ファイルの一覧・アップロード・ダウンロードUI。
 * WorkflowRequestDetailPageの添付ブロックから抽出した汎用コンポーネント。
 * 経費精算の明細行(ExpenseItem)など、1画面に複数の添付パネルを並べる用途にも使う。
 */
export function AttachmentPanel({ ownerType, ownerId, readOnly = false, required = false, compact = false, className }: AttachmentPanelProps) {
  const { data: attachments, isLoading } = useAttachments(ownerType, ownerId)
  const uploadAttachment = useUploadAttachment()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const hasNoAttachments = !isLoading && (attachments ?? []).length === 0
  const textSize = compact ? 'text-xs' : 'text-sm'
  const gap = compact ? 'gap-1' : 'gap-2'
  const rowPadding = compact ? 'py-1' : 'py-1.5'

  return (
    <div className={cn('flex flex-col', gap, className)}>
      {uploadAttachment.error && <ErrorMessage error={uploadAttachment.error} />}
      {isLoading ? (
        <LoadingState />
      ) : (
        <ul className={cn('flex flex-col', textSize)} aria-label="添付ファイル">
          {hasNoAttachments && (
            <li className={cn(rowPadding, 'text-muted-foreground')}>添付ファイルはありません。</li>
          )}
          {attachments?.map((attachment) => (
            <li
              key={attachment.id}
              className={cn(
                'flex items-center justify-between gap-3 border-b border-border last:border-b-0',
                rowPadding,
              )}
            >
              <span className="text-foreground">
                {attachment.file_name}({formatFileSize(attachment.file_size)})
              </span>
              <Button
                variant="secondary"
                size={compact ? 'sm' : undefined}
                onClick={() => void downloadAttachment(attachment.id, attachment.file_name)}
              >
                ダウンロード
              </Button>
            </li>
          ))}
        </ul>
      )}

      {required && hasNoAttachments && (
        <p className={cn(textSize, 'text-warning')}>領収書の添付が必要です</p>
      )}

      {!readOnly && (
        <div className="flex items-center gap-3">
          <input
            ref={fileInputRef}
            type="file"
            className={cn(
              'text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:font-medium file:text-foreground',
              textSize,
              compact ? 'file:px-2 file:py-0.5' : 'file:px-3 file:py-1',
              textSize === 'text-xs' ? 'file:text-xs' : 'file:text-sm',
            )}
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (!file) return
              uploadAttachment.mutate(
                { ownerType, ownerId, file },
                {
                  onSuccess: () => {
                    if (fileInputRef.current) fileInputRef.current.value = ''
                  },
                },
              )
            }}
          />
          {uploadAttachment.isPending && <span className={cn(textSize, 'text-muted-foreground')}>アップロード中...</span>}
        </div>
      )}
    </div>
  )
}
