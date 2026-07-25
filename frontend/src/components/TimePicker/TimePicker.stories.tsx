import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { TimePicker } from './TimePicker'

function Demo() {
  const [value, setValue] = useState<string | undefined>('09:30')
  return <TimePicker id="demo-time" value={value} onChange={setValue} />
}

const meta = {
  title: 'Components/TimePicker',
  component: TimePicker,
} satisfies Meta<typeof TimePicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { value: undefined, onChange: () => {} },
  render: () => <Demo />,
}

export const Empty: Story = {
  args: { value: undefined, onChange: () => {} },
}

export const FifteenMinuteStep: Story = {
  args: { value: '09:30', onChange: () => {}, minuteStep: 15 },
}

export const Disabled: Story = {
  args: { value: '09:30', onChange: () => {}, disabled: true },
}
