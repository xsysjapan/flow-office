import type { Meta, StoryObj } from '@storybook/react-vite'
import { Separator } from './separator'

const meta = {
  title: 'UI/Separator',
  tags: ['autodocs'],
  render: () => (
    <div className="w-64">
      <p className="text-sm">上のセクション</p>
      <Separator className="my-3" />
      <p className="text-sm">下のセクション</p>
    </div>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
