import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveGrantCycleType } from '../../api/types'
import { PaidLeaveGrantCycleTypeField, type PaidLeaveGrantCycleTypeFieldProps } from './PaidLeaveGrantCycleTypeField'

/** 選択を切り替えられるように、値を状態として持たせる。 */
function ControlledField(props: PaidLeaveGrantCycleTypeFieldProps) {
  const [value, setValue] = useState<PaidLeaveGrantCycleType>(props.value)
  return <PaidLeaveGrantCycleTypeField {...props} value={value} onChange={setValue} />
}

const meta = {
  title: 'Components/PaidLeaveGrantCycleTypeField',
  component: PaidLeaveGrantCycleTypeField,
  render: (args) => <ControlledField {...args} />,
} satisfies Meta<typeof PaidLeaveGrantCycleTypeField>

export default meta
type Story = StoryObj<typeof meta>

export const MassGrantMonth: Story = {
  args: {
    value: 'mass_grant_month',
    onChange: () => {},
    defaultFiscalYearStart: { month: 4, day: 1 },
  },
}

export const Anniversary: Story = {
  args: {
    value: 'anniversary',
    onChange: () => {},
    defaultFiscalYearStart: { month: 4, day: 1 },
  },
}

export const NoDefaultCalendar: Story = {
  args: {
    value: 'mass_grant_month',
    onChange: () => {},
    defaultFiscalYearStart: null,
  },
}
