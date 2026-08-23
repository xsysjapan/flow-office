import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { AttendanceCalculationSummary } from './AttendanceCalculationSummary'

async function openDetails() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: '詳しく表示' }))
}

const totals = {
  prescribed_work_minutes: 480,
  statutory_within_overtime_minutes: 60,
  statutory_excess_overtime_minutes: 120,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
  prescribed_holiday_work_minutes: 0,
  late_night_prescribed_holiday_work_minutes: 0,
}

describe('AttendanceCalculationSummary', () => {
  it('defaults to showing only the 4 headline items', () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{ ...totals, statutory_excess_overtime_minutes: 300, late_night_statutory_excess_overtime_minutes: 60 }}
      />,
    )

    expect(screen.getByRole('heading', { name: '今週の集計' })).toBeInTheDocument()
    expect(screen.getByText('所定労働時間')).toBeInTheDocument()
    expect(screen.getByText('残業時間')).toBeInTheDocument()
    expect(screen.getByText('法定休日労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜作業時間')).toBeInTheDocument()

    // 詳細内訳(五分類)はデフォルトでは表示しない
    expect(screen.queryByText('所定内法定内労働時間')).not.toBeInTheDocument()
    expect(screen.queryByText('うち深夜所定外法定外労働時間')).not.toBeInTheDocument()
  })

  it('sums the within/excess statutory overtime fields for the default overtime total', () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{ ...totals, statutory_within_overtime_minutes: 60, statutory_excess_overtime_minutes: 120 }}
      />,
    )

    // 60分 + 120分 = 3時間
    expect(screen.getByText('残業時間').nextElementSibling).toHaveTextContent('3時間3:00')
  })

  it('sums late-night minutes across every category for the default late-night total', () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{
          ...totals,
          late_night_prescribed_work_minutes: 10,
          late_night_statutory_within_overtime_minutes: 20,
          late_night_statutory_excess_overtime_minutes: 30,
          late_night_legal_holiday_work_minutes: 15,
          late_night_prescribed_holiday_work_minutes: 5,
        }}
      />,
    )

    // 10+20+30+15+5 = 80分 = 1時間20分
    expect(screen.getByText('うち深夜作業時間').nextElementSibling).toHaveTextContent('1時間20分')
  })

  it('reveals the full 5-classification breakdown when "詳しく表示" is toggled, and can toggle back', async () => {
    const user = userEvent.setup()
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{ ...totals, statutory_excess_overtime_minutes: 300, late_night_statutory_excess_overtime_minutes: 60 }}
      />,
    )

    const toggle = screen.getByRole('button', { name: '詳しく表示' })
    await user.click(toggle)

    expect(screen.getByText('所定内法定内労働時間').closest('dl')).toHaveClass('grid-cols-[minmax(0,1fr)_auto]', 'sm:grid-cols-[auto_1fr_auto_1fr]')
    expect(screen.getByText('うち深夜所定内法定内労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜所定外法定内労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜所定内法定外労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜所定外法定外労働時間')).toBeInTheDocument()
    expect(screen.getByText('うち深夜法定休日労働時間')).toBeInTheDocument()
    // 4項目のみのデフォルト表示には無いラベル(法定休日労働時間は詳細にも共通で残る)
    expect(screen.queryByText('残業時間')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '簡易表示に戻す' }))
    expect(screen.getByText('残業時間')).toBeInTheDocument()
    expect(screen.queryByText('所定内法定内労働時間')).not.toBeInTheDocument()
  })

  it('can show month-specific and leave totals in detail mode', async () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, absence_minutes: 60, paid_leave_days: 1, special_leave_days: 1 }}
        statutoryExcessOver60hMinutes={30}
        absenceDays={1}
        showAllLeaveTotals
      />,
    )
    await openDetails()

    expect(screen.getByText('うち月60時間超')).toBeInTheDocument()
    expect(screen.getByText('欠勤日数')).toBeInTheDocument()
    expect(screen.getByText('有給日数')).toBeInTheDocument()
    expect(screen.getByText('特別休暇日数')).toBeInTheDocument()
  })

  it('does not show the absence/special-leave breakdown in the default (collapsed) view even when showAllLeaveTotals is set, but still shows the headline paid leave days total', () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, absence_minutes: 60, paid_leave_days: 1, special_leave_days: 1 }}
        absenceDays={1}
        showAllLeaveTotals
      />,
    )

    expect(screen.queryByText('欠勤日数')).not.toBeInTheDocument()
    expect(screen.queryByText('特別休暇日数')).not.toBeInTheDocument()
    expect(screen.getByText('有給日数')).toBeInTheDocument()
    expect(screen.getByText('有給日数').nextElementSibling).toHaveTextContent('1日')
  })

  it('shows the headline actual worked time and paid leave days in the default (collapsed) view when provided', () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={{ ...totals, work_minutes: 9600, paid_leave_days: 0 }}
      />,
    )

    expect(screen.getByText('実労働時間')).toBeInTheDocument()
    expect(screen.getByText('有給日数')).toBeInTheDocument()
    expect(screen.getByText('有給日数').nextElementSibling).toHaveTextContent('0日')
  })

  it('does not show the actual worked time or paid leave days rows in the default view when absent', () => {
    render(<AttendanceCalculationSummary title="今週の集計" totals={totals} />)

    expect(screen.queryByText('実労働時間')).not.toBeInTheDocument()
    expect(screen.queryByText('有給日数')).not.toBeInTheDocument()
  })

  it('shows the weekly 40h overtime total only when provided', async () => {
    const { rerender } = render(<AttendanceCalculationSummary title="今月の集計" totals={totals} />)
    await openDetails()
    expect(screen.queryByText('うち週40時間超')).not.toBeInTheDocument()

    rerender(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={totals}
        statutoryExcessOver60hMinutes={30}
        weeklyStatutoryExcessOvertimeMinutes={45}
      />,
    )
    const over60Hours = screen.getByText('うち月60時間超')
    const weeklyOvertime = screen.getByText('うち週40時間超')
    const legalHoliday = screen.getByText('法定休日労働時間')

    expect(weeklyOvertime).toBeInTheDocument()
    expect(weeklyOvertime.nextElementSibling).not.toHaveClass('sm:col-span-3')
    expect(over60Hours.compareDocumentPosition(weeklyOvertime) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(weeklyOvertime.compareDocumentPosition(legalHoliday) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('puts the weekly 40h overtime on its own row when the monthly 60h item is absent', async () => {
    render(
      <AttendanceCalculationSummary
        title="今週の集計"
        totals={totals}
        weeklyStatutoryExcessOvertimeMinutes={45}
      />,
    )
    await openDetails()

    expect(screen.getByText('うち週40時間超').nextElementSibling).toHaveClass('sm:col-span-3')
  })

  it('shows the total worked time when work_minutes is provided', async () => {
    render(<AttendanceCalculationSummary title="今月の集計" totals={{ ...totals, work_minutes: 9600 }} />)
    await openDetails()

    expect(screen.getByText('実労働時間')).toBeInTheDocument()
  })

  it('does not show the total worked time row when work_minutes is absent', async () => {
    render(<AttendanceCalculationSummary title="今週の集計" totals={totals} />)
    await openDetails()

    expect(screen.queryByText('実労働時間')).not.toBeInTheDocument()
  })

  it('shows a payroll work time row only when it differs from work_minutes', async () => {
    const { rerender } = render(
      <AttendanceCalculationSummary title="今月の集計" totals={{ ...totals, work_minutes: 9600 }} payrollWorkMinutes={9600} />,
    )
    await openDetails()
    expect(screen.queryByText('給与計算上の労働時間')).not.toBeInTheDocument()

    rerender(
      <AttendanceCalculationSummary title="今月の集計" totals={{ ...totals, work_minutes: 9600 }} payrollWorkMinutes={9800} />,
    )
    expect(screen.getByText('給与計算上の労働時間')).toBeInTheDocument()
  })

  it('shows a per-type special leave breakdown when 2 or more types are present', async () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, special_leave_days: 1.5, special_leave_minutes: 120 }}
        showAllLeaveTotals
        specialLeaveBreakdown={[
          { special_leave_type_id: 'type-1', special_leave_type_name: '誕生日休暇', days: 1, minutes: 0 },
          { special_leave_type_id: 'type-2', special_leave_type_name: 'リフレッシュ休暇', days: 0.5, minutes: 120 },
        ]}
      />,
    )
    await openDetails()

    expect(screen.getByText('うち誕生日休暇')).toBeInTheDocument()
    expect(screen.getByText('うちリフレッシュ休暇')).toBeInTheDocument()
  })

  it('shows a per-type breakdown even when only 1 special leave type is present', async () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, special_leave_days: 1 }}
        showAllLeaveTotals
        specialLeaveBreakdown={[
          { special_leave_type_id: 'type-1', special_leave_type_name: '誕生日休暇', days: 1, minutes: 0 },
        ]}
      />,
    )
    await openDetails()

    expect(screen.getByText('うち誕生日休暇')).toBeInTheDocument()
  })

  it('does not show a breakdown row when no special leave was taken', async () => {
    render(
      <AttendanceCalculationSummary
        title="今月の集計"
        totals={{ ...totals, special_leave_days: 0 }}
        showAllLeaveTotals
        specialLeaveBreakdown={[
          { special_leave_type_id: 'type-1', special_leave_type_name: '誕生日休暇', days: 0, minutes: 0 },
        ]}
      />,
    )
    await openDetails()

    expect(screen.queryByText('うち誕生日休暇')).not.toBeInTheDocument()
  })
})
