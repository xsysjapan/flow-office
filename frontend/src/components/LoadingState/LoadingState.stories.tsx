import type { Meta, StoryObj } from '@storybook/react-vite'
import { LoadingState } from './LoadingState'

const meta = {
  title: 'Components/LoadingState',
  component: LoadingState,
  tags: ['autodocs'],
} satisfies Meta<typeof LoadingState>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const CustomLabel: Story = {
  args: { label: '申請を送信しています...' },
}
