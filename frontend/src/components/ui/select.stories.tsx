import type { Meta, StoryObj } from '@storybook/react-vite'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './select'

const meta = {
  title: 'UI/Select',
  tags: ['autodocs'],
  render: () => (
    <Select defaultValue="full">
      <SelectTrigger className="w-48">
        <SelectValue placeholder="種別を選択" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="full">全休</SelectItem>
        <SelectItem value="am_half">午前半休</SelectItem>
        <SelectItem value="pm_half">午後半休</SelectItem>
        <SelectItem value="hourly">時間休</SelectItem>
      </SelectContent>
    </Select>
  ),
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
