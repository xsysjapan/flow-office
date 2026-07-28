import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { ConfirmDialog } from './ConfirmDialog'

const meta = {
  title: 'Components/ConfirmDialog',
  component: ConfirmDialog,
  tags: ['autodocs'],
} satisfies Meta<typeof ConfirmDialog>

export default meta
type Story = StoryObj<typeof meta>

export const DeleteConfirmation: Story = {
  args: {
    trigger: (
      <Button variant="danger" size="sm">
        削除
      </Button>
    ),
    title: 'この下書きを削除しますか?',
    description: '削除すると元に戻せません。',
    onConfirm: () => {},
  },
}

export const ConfirmingState: Story = {
  args: {
    trigger: (
      <Button variant="danger" size="sm">
        削除
      </Button>
    ),
    title: 'この下書きを削除しますか?',
    description: '削除すると元に戻せません。',
    isConfirming: true,
    onConfirm: () => {},
  },
}
