import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { DateRangePicker, type DateRangeValue } from './DateRangePicker'

function Demo() {
  const [value, setValue] = useState<DateRangeValue | undefined>({ from: '2026-08-15', to: '2026-08-19' })
  return <DateRangePicker id="demo-date-range" value={value} onChange={setValue} />
}

const meta = {
  title: 'Components/DateRangePicker',
  component: DateRangePicker,
} satisfies Meta<typeof DateRangePicker>

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
  args: { value: { from: '2026-08-15', to: '2026-08-19' }, onChange: () => {}, disabled: true },
}

export const WithMinMax: Story = {
  args: { value: { from: '2026-08-15', to: '2026-08-19' }, onChange: () => {}, min: '2026-08-10', max: '2026-08-25' },
}
