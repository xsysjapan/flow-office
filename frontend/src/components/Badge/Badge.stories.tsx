import type { Meta, StoryObj } from '@storybook/react-vite'
import { Badge } from './Badge'

const meta = {
  title: 'Components/Badge',
  component: Badge,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component: 'ページ実装で使う公開コンポーネント。内部で`ui/badge`をラップし、業務ステータスの意味を持つ`tone`propのみを受け取る。',
      },
    },
  },
  argTypes: {
    tone: {
      control: 'select',
      options: ['neutral', 'info', 'success', 'warning', 'danger'],
    },
  },
} satisfies Meta<typeof Badge>

export default meta
type Story = StoryObj<typeof meta>

export const Neutral: Story = {
  args: { tone: 'neutral', children: '下書き' },
}

export const Info: Story = {
  args: { tone: 'info', children: '提出済み' },
}

export const Success: Story = {
  args: { tone: 'success', children: '承認済み' },
}

export const Warning: Story = {
  args: { tone: 'warning', children: '差戻し' },
}

export const Danger: Story = {
  args: { tone: 'danger', children: '取消' },
}
