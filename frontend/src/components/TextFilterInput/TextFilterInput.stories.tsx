import { useState, type ComponentProps } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { TextFilterInput } from './TextFilterInput'

const meta = {
  title: 'Components/TextFilterInput',
  component: TextFilterInput,
  tags: ['autodocs'],
  args: { label: 'キーワード', value: null, onChange: fn() },
} satisfies Meta<typeof TextFilterInput>

function ControlledExample(args: ComponentProps<typeof TextFilterInput>) {
  const [value, setValue] = useState<string | null>(null)
  return (
    <div className="w-72 space-y-2">
      <TextFilterInput {...args} value={value} onChange={setValue} />
      <p className="text-sm text-muted-foreground">反映値: {value ?? '(なし)'}</p>
    </div>
  )
}

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { placeholder: 'タイトル・種別・担当部署・担当者名' },
}

export const WithValue: Story = {
  args: { value: '山田', placeholder: 'タイトル・種別・担当部署・担当者名' },
}

export const Controlled: Story = {
  render: (args) => <ControlledExample {...args} />,
}
