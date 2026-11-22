import { useState, type ComponentProps } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { ListQueueTabs } from './ListQueueTabs'

const meta = {
  title: 'Components/ListQueueTabs',
  component: ListQueueTabs,
  tags: ['autodocs'],
  args: {
    'aria-label': '承認状態',
    value: 'submitted',
    onChange: fn(),
    tabs: [
      { value: 'submitted', label: '承認待ち', count: 12 },
      { value: 'approved', label: '承認済み', count: 30 },
      { value: 'returned', label: '差戻し済み', count: 2 },
      { value: 'cancelled', label: '取消', count: 1 },
      { value: 'all', label: 'すべて', count: 46 },
    ],
  },
} satisfies Meta<typeof ListQueueTabs>

function ControlledExample(args: ComponentProps<typeof ListQueueTabs>) {
  const [value, setValue] = useState(args.value)
  return <ListQueueTabs {...args} value={value} onChange={setValue} />
}

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithoutCount: Story = {
  args: {
    tabs: [
      { value: 'all', label: 'すべて' },
      { value: 'unread', label: '未読' },
      { value: 'read', label: '既読' },
    ],
    value: 'all',
  },
}

export const Controlled: Story = {
  render: (args) => <ControlledExample {...args} />,
}
