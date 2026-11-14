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
      work_minutes: 10080,
      prescribed_work_minutes: 9600,
      prescribed_statutory_within_work_minutes: 9600,
      prescribed_statutory_excess_work_minutes: 0,
      non_prescribed_statutory_within_work_minutes: 120,
      non_prescribed_statutory_excess_work_minutes: 480,
      statutory_within_overtime_minutes: 120,
      statutory_excess_overtime_minutes: 480,
      worked_days: 20,
      late_night_prescribed_work_minutes: 0,
      late_night_statutory_within_overtime_minutes: 0,
      late_night_statutory_excess_overtime_minutes: 60,
      legal_holiday_work_minutes: 0,
      late_night_legal_holiday_work_minutes: 0,
      prescribed_holiday_work_minutes: 0,
      late_night_prescribed_holiday_work_minutes: 0,
      absence_minutes: 0,
      paid_leave_days: 1,
      paid_leave_minutes: 120,
      special_leave_days: 1.5,
      special_leave_minutes: 120,
    },
    statutoryExcessOver60hMinutes: 0,
    weeklyStatutoryExcessOvertimeMinutes: 0,
    absenceDays: 0,
    showAllLeaveTotals: true,
    // 所定労働日数・所定労働時間は勤務予定ベース(未入力の労働日を含む月全体)。
    // 日次の集計(20日×480分)より大きい値になる例。
    prescribedWorkDays: 21,
    prescribedWorkMinutes: 10080,
  },
}

export const MonthlyWithSpecialLeaveBreakdown: Story = {
  args: {
    ...Monthly.args,
    specialLeaveBreakdown: [
      { special_leave_type_id: 'type-1', special_leave_type_name: '誕生日休暇', days: 1, minutes: 0 },
      { special_leave_type_id: 'type-2', special_leave_type_name: 'リフレッシュ休暇', days: 0.5, minutes: 120 },
    ],
  },
}

/** デフォルト表示: 実労働時間・実労働日数・所定内労働時間・残業時間・法定休日労働時間・
 *  うち深夜作業時間・有給日数。所定内労働時間は所定内法定内+所定内法定外、残業時間は
 *  所定外法定内+所定外法定外(実労働時間の内訳)。実労働時間・実労働日数・有給日数は値が提供されている場合のみ。
 *  「詳しく表示」を押すと、先頭に所定労働日数・所定労働時間(Monthly相当の値)と実労働日数・実労働時間を並べ、
 *  五分類の内訳に切り替わる。 */
export const DefaultCollapsed: Story = {
  args: {
    ...Monthly.args,
  },
}