import type { Meta, StoryObj } from '@storybook/react-vite'
import { AttendanceCalculationSummary } from './AttendanceCalculationSummary'

const meta = {
  title: 'Components/AttendanceCalculationSummary',
  component: AttendanceCalculationSummary,
  tags: ['autodocs'],
} satisfies Meta<typeof AttendanceCalculationSummary>

export default meta
type Story = StoryObj<typeof meta>

export const Monthly: Story = {
  args: {
    title: '今月の集計',
    totals: {
      prescribed_work_minutes: 9600,
      statutory_within_overtime_minutes: 120,
      statutory_excess_overtime_minutes: 480,
      late_night_prescribed_work_minutes: 0,
      late_night_statutory_within_overtime_minutes: 0,
      late_night_statutory_excess_overtime_minutes: 60,
      legal_holiday_work_minutes: 0,
      late_night_legal_holiday_work_minutes: 0,
      absence_minutes: 0,
      paid_leave_days: 1,
      paid_leave_minutes: 120,
      special_leave_minutes: 0,
    },
    statutoryExcessOver60hMinutes: 0,
    absenceDays: 0,
    showAllLeaveTotals: true,
  },
}