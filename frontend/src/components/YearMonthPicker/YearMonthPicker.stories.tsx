import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'
import { YearMonthPicker } from './YearMonthPicker'

function Demo() {
  const [value, setValue] = useState<string | undefined>('2026-07')
  return <div className="w-72"><YearMonthPicker value={value} onChange={setValue} /></div>
}

const meta = {
  title: 'Components/YearMonthPicker',
  component: YearMonthPicker,
  render: () => <Demo />,
} satisfies Meta<typeof YearMonthPicker>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    value: undefined,
    onChange: () => {},
  },
}
