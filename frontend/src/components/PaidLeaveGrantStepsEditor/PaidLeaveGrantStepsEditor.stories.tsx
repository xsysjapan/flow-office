import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveGrantPolicies, PaidLeaveGrantRuleStep } from '../../api/types'
import { PaidLeaveGrantStepsEditor, type PaidLeaveGrantStepsEditorProps } from './PaidLeaveGrantStepsEditor'

const statutory: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
    { continuous_service_months: 30, grant_days: 12 },
  ],
  proportional_version: 'v1',
  proportional: [
    { weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 },
    { weekly_scheduled_days_category: '3', continuous_service_months: 6, grant_days: 5 },
  ],
}

const initialSteps: PaidLeaveGrantRuleStep[] = [
  { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
  { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
  { grant_category: 'normal', continuous_service_months: 30, grant_days: 12 },
  { grant_category: 'proportional_4', continuous_service_months: 6, grant_days: 7 },
  { grant_category: 'proportional_3', continuous_service_months: 6, grant_days: 5 },
]

/** 行の追加・削除・編集を動かせるように、stepsを状態として持たせる。 */
function ControlledEditor(props: PaidLeaveGrantStepsEditorProps) {
  const [steps, setSteps] = useState(props.steps)
  return <PaidLeaveGrantStepsEditor {...props} steps={steps} onChange={setSteps} />
}

const meta = {
  title: 'Components/PaidLeaveGrantStepsEditor',
  component: PaidLeaveGrantStepsEditor,
  render: (args) => <ControlledEditor {...args} />,
} satisfies Meta<typeof PaidLeaveGrantStepsEditor>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    steps: initialSteps,
    onChange: () => {},
    statutory,
  },
}

export const WithFieldError: Story = {
  args: {
    steps: initialSteps,
    onChange: () => {},
    statutory,
    fieldErrors: { 'steps.3.grant_days': ['週4日の法定最低日数は7日です。'] },
  },
}
