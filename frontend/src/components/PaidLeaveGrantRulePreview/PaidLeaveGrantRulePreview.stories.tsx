import type { Meta, StoryObj } from '@storybook/react-vite'
import { PaidLeaveGrantRulePreview } from './PaidLeaveGrantRulePreview'

const meta = {
  title: 'Components/PaidLeaveGrantRulePreview',
  component: PaidLeaveGrantRulePreview,
} satisfies Meta<typeof PaidLeaveGrantRulePreview>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    firstGrantAfterMonths: 6,
    grantCycleMonths: 12,
    minAttendanceRate: 80,
    steps: [
      { continuous_service_months: 6, grant_days: 10 },
      { continuous_service_months: 18, grant_days: 11 },
      { continuous_service_months: 30, grant_days: 12 },
    ],
  },
}

export const NoSteps: Story = {
  args: {
    firstGrantAfterMonths: 6,
    grantCycleMonths: 12,
    minAttendanceRate: 80,
    steps: [],
  },
}
