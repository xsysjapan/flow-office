import type { Meta, StoryObj } from '@storybook/react-vite'
import { Badge } from './badge'

const meta = {
  title: 'UI/Badge',
  component: Badge,
  tags: ['autodocs'],
  argTypes: {
    variant: {
      control: 'select',
      options: ['neutral', 'info', 'success', 'warning', 'destructive'],
    },
  },
} satisfies Meta<typeof Badge>

export default meta
type Story = StoryObj<typeof meta>

export const Neutral: Story = { args: { variant: 'neutral', children: '下書き' } }
export const Info: Story = { args: { variant: 'info', children: '提出済み' } }
export const Success: Story = { args: { variant: 'success', children: '承認済み' } }
export const Warning: Story = { args: { variant: 'warning', children: '差戻し' } }
export const Destructive: Story = { args: { variant: 'destructive', children: '取消' } }
