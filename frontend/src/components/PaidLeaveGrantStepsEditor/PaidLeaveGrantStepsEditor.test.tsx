import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { PaidLeaveGrantPolicies, PaidLeaveGrantRuleStep } from '../../api/types'
import { PaidLeaveGrantStepsEditor } from './PaidLeaveGrantStepsEditor'

const statutory: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
  ],
  proportional_version: 'v1',
  proportional: [{ weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 }],
}

const steps: PaidLeaveGrantRuleStep[] = [
  { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
  { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
  { grant_category: 'proportional_4', continuous_service_months: 6, grant_days: 7 },
]

describe('PaidLeaveGrantStepsEditor', () => {
  it('shows the normal rows first, with the statutory minimum for each row', () => {
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={vi.fn()} statutory={statutory} />)

    expect(screen.getByRole('tab', { name: '通常' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByLabelText('通常 継続勤務6か月の付与日数')).toHaveValue(10)
    expect(screen.getByLabelText('通常 継続勤務18か月の付与日数')).toHaveValue(11)
    expect(screen.getByText('(この継続勤務月数の法定最低日数: 10日)')).toBeInTheDocument()
    expect(screen.queryByLabelText('週4日 継続勤務6か月の付与日数')).not.toBeInTheDocument()
  })

  it('switches to a proportional tab and shows only that category rows', async () => {
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={vi.fn()} statutory={statutory} />)

    await userEvent.click(screen.getByRole('tab', { name: '週4日' }))

    expect(screen.getByLabelText('週4日 継続勤務6か月の付与日数')).toHaveValue(7)
    expect(screen.queryByLabelText('通常 継続勤務6か月の付与日数')).not.toBeInTheDocument()
    expect(screen.getByText('追加先: 週4日')).toBeInTheDocument()
  })

  it('shows an empty message for a category with no rows', async () => {
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={vi.fn()} statutory={statutory} />)

    await userEvent.click(screen.getByRole('tab', { name: '週1日' }))
    expect(screen.getByText('この区分の付与日数はまだありません。')).toBeInTheDocument()
  })

  it('reports the edited days with the same grant_category', () => {
    const onChange = vi.fn()
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={onChange} statutory={statutory} />)

    fireEvent.change(screen.getByLabelText('通常 継続勤務18か月の付与日数'), { target: { value: '12' } })

    expect(onChange).toHaveBeenCalledWith([
      steps[0],
      { grant_category: 'normal', continuous_service_months: 18, grant_days: 12 },
      steps[2],
    ])
  })

  it('removes a row of the active category', async () => {
    const onChange = vi.fn()
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={onChange} statutory={statutory} />)

    await userEvent.click(screen.getByRole('button', { name: '通常 継続勤務18か月の行を削除' }))

    expect(onChange).toHaveBeenCalledWith([steps[0], steps[2]])
  })

  it('adds a row to the active category with its grant_category', async () => {
    const onChange = vi.fn()
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={onChange} statutory={statutory} />)

    await userEvent.type(screen.getByLabelText('継続勤務(か月)'), '30')
    await userEvent.type(screen.getByLabelText('付与日数'), '12')
    await userEvent.click(screen.getByRole('button', { name: '追加' }))

    expect(onChange).toHaveBeenCalledWith([...steps, { grant_category: 'normal', continuous_service_months: 30, grant_days: 12 }])
  })

  it('disables adding a duplicate months value in the same category, with the reason', async () => {
    render(<PaidLeaveGrantStepsEditor steps={steps} onChange={vi.fn()} statutory={statutory} />)

    await userEvent.type(screen.getByLabelText('継続勤務(か月)'), '6')
    await userEvent.type(screen.getByLabelText('付与日数'), '9')

    expect(screen.getByRole('button', { name: '追加' })).toBeDisabled()
    expect(screen.getByText('この区分には、同じ継続勤務月数の行があります。')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: '週3日' }))
    expect(screen.getByRole('button', { name: '追加' })).toBeEnabled()
  })

  it('shows the field error on the tab of the category that has the row', async () => {
    render(
      <PaidLeaveGrantStepsEditor
        steps={steps}
        onChange={vi.fn()}
        statutory={statutory}
        fieldErrors={{ 'steps.2.grant_days': ['週4日の法定最低日数は7日です。'] }}
      />,
    )

    expect(screen.getByRole('tab', { name: '週4日(エラーあり)' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '通常' })).not.toHaveTextContent('エラーあり')
    expect(screen.queryByText('週4日の法定最低日数は7日です。')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: '週4日(エラーあり)' }))
    expect(screen.getByText('週4日の法定最低日数は7日です。')).toBeInTheDocument()
  })
})
