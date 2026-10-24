import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { PaidLeaveGrantCycleTypeField } from './PaidLeaveGrantCycleTypeField'

describe('PaidLeaveGrantCycleTypeField', () => {
  it('selects the given value and shows the default calendar year start only for mass grant', () => {
    const { rerender } = render(
      <PaidLeaveGrantCycleTypeField value="mass_grant_month" onChange={vi.fn()} defaultFiscalYearStart={{ month: 4, day: 1 }} />,
    )

    expect(screen.getByLabelText('年度はじめに一斉付与')).toBeChecked()
    expect(screen.getByLabelText('入社日を基準に付与')).not.toBeChecked()
    expect(screen.getByText('既定の会社カレンダーの年度開始日: 4月1日')).toBeInTheDocument()

    rerender(
      <PaidLeaveGrantCycleTypeField value="anniversary" onChange={vi.fn()} defaultFiscalYearStart={{ month: 4, day: 1 }} />,
    )
    expect(screen.getByLabelText('入社日を基準に付与')).toBeChecked()
    expect(screen.queryByText(/既定の会社カレンダーの年度開始日/)).not.toBeInTheDocument()
  })

  it('reports the chosen cycle type when the other option is clicked', async () => {
    const onChange = vi.fn()
    render(<PaidLeaveGrantCycleTypeField value="mass_grant_month" onChange={onChange} defaultFiscalYearStart={null} />)

    await userEvent.click(screen.getByLabelText('入社日を基準に付与'))
    expect(onChange).toHaveBeenCalledWith('anniversary')
  })

  it('shows 4月1日(既定) when there is no default calendar', () => {
    render(<PaidLeaveGrantCycleTypeField value="mass_grant_month" onChange={vi.fn()} defaultFiscalYearStart={null} />)
    expect(screen.getByText('既定の会社カレンダーの年度開始日: 4月1日(既定)')).toBeInTheDocument()
  })

  it('shows the default calendar year start from the calendar, not the fallback', () => {
    render(<PaidLeaveGrantCycleTypeField value="mass_grant_month" onChange={vi.fn()} defaultFiscalYearStart={{ month: 10, day: 1 }} />)
    expect(screen.getByText('既定の会社カレンダーの年度開始日: 10月1日')).toBeInTheDocument()
    expect(screen.queryByText(/既定\)/)).not.toBeInTheDocument()
  })
})
