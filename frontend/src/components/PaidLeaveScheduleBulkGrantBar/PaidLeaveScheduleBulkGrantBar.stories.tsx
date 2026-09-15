import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { PaidLeaveScheduleBulkGrantBar } from './PaidLeaveScheduleBulkGrantBar'

const meta = {
  title: 'Components/PaidLeaveScheduleBulkGrantBar',
  component: PaidLeaveScheduleBulkGrantBar,
  args: {
    onCancel: fn(),
    onBulkGrant: fn(),
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
