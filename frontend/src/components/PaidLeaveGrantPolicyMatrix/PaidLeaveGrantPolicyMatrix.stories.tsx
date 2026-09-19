import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveGrantPolicies } from '../../api/types'
import { PaidLeaveGrantPolicyMatrix } from './PaidLeaveGrantPolicyMatrix'

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
    { continuous_service_months: 30, grant_days: 12 },
    { continuous_service_months: 42, grant_days: 14 },
    { continuous_service_months: 54, grant_days: 16 },
    { continuous_service_months: 66, grant_days: 18 },
    { continuous_service_months: 78, grant_days: 20 },
  ],
  proportional_version: 'v1',
  proportional: [
    { weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 },
    { weekly_scheduled_days_category: '4', continuous_service_months: 18, grant_days: 8 },
    { weekly_scheduled_days_category: '3', continuous_service_months: 6, grant_days: 5 },
    { weekly_scheduled_days_category: '2', continuous_service_months: 6, grant_days: 3 },
    { weekly_scheduled_days_category: '1', continuous_service_months: 6, grant_days: 1 },
  ],
}

const meta = {
  title: 'Components/PaidLeaveGrantPolicyMatrix',
  component: PaidLeaveGrantPolicyMatrix,
} satisfies Meta<typeof PaidLeaveGrantPolicyMatrix>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { policies },
}
