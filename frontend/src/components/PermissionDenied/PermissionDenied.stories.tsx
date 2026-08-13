import type { Meta, StoryObj } from '@storybook/react-vite'
import { PermissionDenied } from './PermissionDenied'

const meta = {
  title: 'Components/PermissionDenied',
  component: PermissionDenied,
  tags: ['autodocs'],
} satisfies Meta<typeof PermissionDenied>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const CustomMessage: Story = {
  args: { message: 'このグループを編集する権限がありません。' },
}
