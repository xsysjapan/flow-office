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
    grantCycleType: 'anniversary',
    fiscalYearStart: { month: 4, day: 1 },
    firstGrantAfterMonths: 6,
    grantCycleMonths: 12,
    minAttendanceRate: 80,
    steps: [
      { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
      { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
      { grant_category: 'normal', continuous_service_months: 30, grant_days: 12 },
    ],
  },
}

export const MassGrantMonth: Story = {
  args: {
    grantCycleType: 'mass_grant_month',
    fiscalYearStart: { month: 4, day: 1 },
    firstGrantAfterMonths: 6,
    grantCycleMonths: 12,
    minAttendanceRate: 80,
    steps: [
      { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
      { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
      { grant_category: 'proportional_4', continuous_service_months: 6, grant_days: 7 },
    ],
  },
}

export const NoSteps: Story = {
  args: {
    grantCycleType: 'anniversary',
    fiscalYearStart: { month: 4, day: 1 },
    firstGrantAfterMonths: 6,
    grantCycleMonths: 12,
    minAttendanceRate: 80,
    steps: [],
  },
}
