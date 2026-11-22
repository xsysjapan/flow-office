import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { BulkActionBar } from './BulkActionBar'

const meta = {
  title: 'Components/BulkActionBar',
  component: BulkActionBar,
  args: {
    selectedCount: 3,
    onClear: fn(),
    actions: [
      { key: 'assign', label: '担当者を変更', onClick: fn() },
      { key: 'complete', label: '完了にする', onClick: fn() },
    ],
  },
} satisfies Meta<typeof BulkActionBar>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const NothingSelected: Story = { args: { selectedCount: 0 } }

export const DisabledWithReason: Story = {
  args: {
    actions: [
      { key: 'assign', label: '担当者を変更', onClick: fn() },
      { key: 'complete', label: '完了にする', onClick: fn(), disabled: true, disabledReason: '自分のタスクのみ完了にできます' },
    ],
  },
}

export const FiveActionsWithMenu: Story = {
  args: {
    actions: [
      { key: 'a', label: '操作A', onClick: fn() },
      { key: 'b', label: '操作B', onClick: fn() },
      { key: 'c', label: '操作C', onClick: fn() },
      { key: 'd', label: '操作D', onClick: fn() },
      { key: 'e', label: '操作E', onClick: fn(), variant: 'danger' },
    ],
  },
}
