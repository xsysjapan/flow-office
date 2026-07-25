import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { Calendar } from './calendar'

function Demo() {
  const [selected, setSelected] = useState<Date | undefined>(new Date('2026-08-15'))
  return <Calendar mode="single" selected={selected} onSelect={setSelected} />
}

const meta = {
  title: 'UI/Calendar',
  component: Calendar,
  tags: ['autodocs'],
} satisfies Meta<typeof Calendar>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Demo />,
}
