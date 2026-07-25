import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { DateTimePicker } from './DateTimePicker'

function Demo() {
  const [value, setValue] = useState<string | undefined>('2026-08-15T09:30')
  return <DateTimePicker id="demo-datetime" value={value} onChange={setValue} />
}

const meta = {
  title: 'Components/DateTimePicker',
  component: DateTimePicker,
} satisfies Meta<typeof DateTimePicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Demo />,
}

export const Empty: Story = {
  args: { value: undefined, onChange: () => {} },
}

export const Disabled: Story = {
  args: { value: '2026-08-15T09:30', onChange: () => {}, disabled: true },
}
