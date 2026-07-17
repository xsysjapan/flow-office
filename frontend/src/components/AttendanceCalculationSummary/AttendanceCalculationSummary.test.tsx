import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AttendanceCalculationSummary } from './AttendanceCalculationSummary'

const totals = {
  prescribed_work_minutes: 480,
  statutory_within_overtime_minutes: 60,
  statutory_excess_overtime_minutes: 120,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
}

describe('AttendanceCalculationSummary', () => {
  it('uses the monthly mobile layout with one label and value pair per row', () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{ ...totals, statutory_excess_overtime_minutes: 300, late_night_statutory_excess_overtime_minutes: 60 }}
      />,
    )

    expect(screen.getByRole('heading', { name: '今週の集計' })).toBeInTheDocument()
    expect(screen.getByText('所定労働時間').closest('dl')).toHaveClass('grid-cols-[minmax(0,1fr)_auto]', 'sm:grid-cols-[auto_1fr_auto_1fr]')
    expect(screen.getByText('うち深夜所定労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜法定内残業時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜法定外残業時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜法定休日労働時間')).toBeInTheDocument()
  })

  it('can show month-specific and leave totals', () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, absence_minutes: 60, paid_leave_days: 1, special_leave_days: 1 }}
        statutoryExcessOver60hMinutes={30}
        absenceDays={1}
        showAllLeaveTotals
      />,
    )

    expect(screen.getByText('うち月60時間超')).toBeInTheDocument()
    expect(screen.getByText('欠勤日数')).toBeInTheDocument()
    expect(screen.getByText('有給日数')).toBeInTheDocument()
    expect(screen.getByText('特別休暇日数')).toBeInTheDocument()
  })
})