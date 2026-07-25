import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { DatePicker } from './DatePicker'

function Demo() {
  const [value, setValue] = useState<string | undefined>('2026-08-15')
  return <DatePicker id="demo-date" value={value} onChange={setValue} />
}

const meta = {
  title: 'Components/DatePicker',
  component: DatePicker,
} satisfies Meta<typeof DatePicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { value: undefined, onChange: () => {} },
  render: () => <Demo />,
}

export const Empty: Story = {
  args: { value: undefined, onChange: () => {} },
}

export const Disabled: Story = {
  args: { value: '2026-08-15', onChange: () => {}, disabled: true },
}

export const WithMinMax: Story = {
  args: { value: '2026-08-15', onChange: () => {}, min: '2026-08-10', max: '2026-08-20' },
}
