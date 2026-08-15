import type { Meta, StoryObj } from '@storybook/react-vite'
import { RadioGroup, RadioGroupItem } from './radio-group'
import { Label } from './label'

const meta = {
  title: 'UI/RadioGroup',
  component: RadioGroup,
  tags: ['autodocs'],
} satisfies Meta<typeof RadioGroup>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <RadioGroup defaultValue="default" {...args}>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="default" id="mode-default" />
        <Label htmlFor="mode-default">会社のデフォルトを使用</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="specify" id="mode-specify" />
        <Label htmlFor="mode-specify">別の働き方を指定</Label>
      </div>
    </RadioGroup>
  ),
}

export const Disabled: Story = { ...Default, args: { disabled: true } }
