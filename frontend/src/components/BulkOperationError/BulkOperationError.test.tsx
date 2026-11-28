import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { BULK_REJECTED_NOTICE } from '../BulkActionDialog/BulkActionDialog'
import { BulkOperationError } from './BulkOperationError'

describe('BulkOperationError', () => {
  it('shows the notice and every rejected item with its label for a bulk rejection', () => {
    const error = new ApiError(
      422,
      '選んだ対象のうち、処理できないものがあります。',
      { 'items.a-1': ['自分が借用中の備品のみ返却できます。'] },
      'bulk_operation_rejected',
      [
        { id: 'a-1', message: '自分が借用中の備品のみ返却できます。' },
        { id: 'a-2', label: 'サーバーの表示名', message: '現在アクティブな貸出がありません。' },
      ],
    )

    render(<BulkOperationError error={error} labels={{ 'a-1': 'ThinkPad(EQ-1)' }} />)

    expect(screen.getByText(BULK_REJECTED_NOTICE)).toBeInTheDocument()
    expect(screen.getByText('ThinkPad(EQ-1): 自分が借用中の備品のみ返却できます。')).toBeInTheDocument()
    expect(screen.getByText('サーバーの表示名: 現在アクティブな貸出がありません。')).toBeInTheDocument()
  })

  it('falls back to the id when no label is known', () => {
    const error = new ApiError(422, 'x', {}, 'bulk_operation_rejected', [{ id: 'a-9', message: '失敗' }])

    render(<BulkOperationError error={error} />)

    expect(screen.getByText('a-9: 失敗')).toBeInTheDocument()
  })

  it('shows a normal error message for other errors', () => {
    render(<BulkOperationError error={new ApiError(500, 'サーバーエラーです。')} fallback="一括操作に失敗しました。" />)

    expect(screen.getByText('サーバーエラーです。')).toBeInTheDocument()
    expect(screen.queryByText(BULK_REJECTED_NOTICE)).not.toBeInTheDocument()
  })
})
