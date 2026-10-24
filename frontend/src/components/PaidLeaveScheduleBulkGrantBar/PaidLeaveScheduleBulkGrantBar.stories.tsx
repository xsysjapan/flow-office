import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { PaidLeaveScheduleBulkGrantBar } from './PaidLeaveScheduleBulkGrantBar'

const meta = {
  title: 'Components/PaidLeaveScheduleBulkGrantBar',
  component: PaidLeaveScheduleBulkGrantBar,
  args: {
    onCancel: fn(),
    onBulkGrant: fn(),
    onConfirmOpenChange: fn(),
    confirmDescription: '3件(合計31日)を付与します。付与後は付与予定画面から取り消せません。取消は使用状況から付与ごとに行います。',
  },
} satisfies Meta<typeof PaidLeaveScheduleBulkGrantBar>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { selectedCount: 3 },
}

export const Submitting: Story = {
  args: { selectedCount: 3, isSubmitting: true },
}
