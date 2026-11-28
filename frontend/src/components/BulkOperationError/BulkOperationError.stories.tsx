import type { Meta, StoryObj } from '@storybook/react-vite'
import { ApiError } from '../../api/client'
import { BulkOperationError } from './BulkOperationError'

const meta = {
  title: 'Components/BulkOperationError',
  component: BulkOperationError,
} satisfies Meta<typeof BulkOperationError>

export default meta
type Story = StoryObj<typeof meta>

export const Rejected: Story = {
  args: {
    error: new ApiError(422, '選んだ対象のうち、処理できないものがあります。', {}, 'bulk_operation_rejected', [
      { id: 'asset-1', message: '自分が借用中の備品のみ返却できます。' },
      { id: 'asset-2', message: '現在アクティブな貸出がありません。' },
    ]),
    labels: { 'asset-1': 'ThinkPad X1(EQ-00121)', 'asset-2': 'モニター(EQ-00200)' },
  },
}

export const OtherError: Story = {
  args: { error: new ApiError(500, 'サーバーエラーです。') },
}
