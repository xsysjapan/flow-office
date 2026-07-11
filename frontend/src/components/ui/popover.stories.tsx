import type { Meta, StoryObj } from '@storybook/react-vite'
import { Popover, PopoverContent, PopoverTrigger } from './popover'
import { Button } from './button'

const meta = {
  title: 'UI/Popover',
  tags: ['autodocs'],
  render: () => (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline">絞り込み</Button>
      </PopoverTrigger>
      <PopoverContent className="p-4 text-sm">期間・ステータスで絞り込めます。</PopoverContent>
    </Popover>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
