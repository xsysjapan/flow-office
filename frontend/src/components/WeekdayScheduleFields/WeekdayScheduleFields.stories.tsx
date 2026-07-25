import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { defaultWeeklyPatternState, WeekdayScheduleFields, type WeekdayRowState } from './WeekdayScheduleFields'

function Demo() {
  const [state, setState] = useState<Record<number, WeekdayRowState>>(defaultWeeklyPatternState())

  return (
    <WeekdayScheduleFields
      state={state}
      onChange={(iso, patch) => setState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))}
    />
  )
}

const meta = {
  title: 'Components/WeekdayScheduleFields',
  component: WeekdayScheduleFields,
} satisfies Meta<typeof WeekdayScheduleFields>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { state: defaultWeeklyPatternState(), onChange: () => {} },
  render: () => <Demo />,
}
