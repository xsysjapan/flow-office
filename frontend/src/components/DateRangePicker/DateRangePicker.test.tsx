import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { DateRangePicker, type DateRangeValue } from './DateRangePicker'

function ControlledDateRangePicker({
  onChange,
  initialValue,
}: {
  onChange: (range: DateRangeValue | undefined) => void
  initialValue?: DateRangeValue
}) {
  const [value, setValue] = useState<DateRangeValue | undefined>(initialValue)

  return (
    <DateRangePicker
      id="target-range"
      value={value}
      onChange={(range) => {
        setValue(range)
        onChange(range)
      }}
    />
  )
}

describe('DateRangePicker', () => {
  it('shows the placeholder when no range is selected', () => {
    render(<DateRangePicker value={undefined} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '期間を選択' })).toBeInTheDocument()
  })

  it('shows a single selected day as the trigger label', () => {
    render(<DateRangePicker value={{ from: '2026-08-15' }} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '2026-08-15' })).toBeInTheDocument()
  })

  it('shows the full range as the trigger label', () => {
    render(<DateRangePicker value={{ from: '2026-08-15', to: '2026-08-19' }} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '2026-08-15 〜 2026-08-19' })).toBeInTheDocument()
  })

  it('selects a start and end day and reports the range', async () => {
    const onChange = vi.fn()
    render(<ControlledDateRangePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '期間を選択' }))
    await userEvent.click(await screen.findByText('15'))
    await userEvent.click(await screen.findByText('19'))

    expect(onChange).toHaveBeenLastCalledWith({
      from: expect.stringMatching(/^\d{4}-\d{2}-15$/),
      to: expect.stringMatching(/^\d{4}-\d{2}-19$/),
    })
  })

  it('closes the calendar popover when 適用 is clicked after selecting a range', async () => {
    render(<ControlledDateRangePicker onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '期間を選択' }))
    await userEvent.click(await screen.findByText('15'))
    await userEvent.click(await screen.findByText('19'))
    await userEvent.click(await screen.findByRole('button', { name: '適用' }))

    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('navigates to the previous and next months', async () => {
    render(<DateRangePicker value={{ from: '2026-08-15' }} onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: '2026-08-15' }))

    await userEvent.click(screen.getByRole('button', { name: '前の月へ' }))
    expect(screen.getByText('2026年7月')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '次の月へ' }))
    expect(screen.getByText('2026年8月')).toBeInTheDocument()
  })

  it('clears the selected range via the クリア button', async () => {
    const onChange = vi.fn()
    render(<ControlledDateRangePicker initialValue={{ from: '2026-08-15', to: '2026-08-19' }} onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '2026-08-15 〜 2026-08-19' }))
    await userEvent.click(await screen.findByRole('button', { name: 'クリア' }))

    expect(onChange).toHaveBeenCalledWith(undefined)
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('does not show the クリア button when no range is selected', async () => {
    render(<DateRangePicker value={undefined} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '期間を選択' }))

    expect(screen.queryByRole('button', { name: 'クリア' })).not.toBeInTheDocument()
  })

  it('does not select a date before min', async () => {
    const onChange = vi.fn()
    render(<DateRangePicker value={{ from: '2026-08-15' }} onChange={onChange} min="2026-08-10" />)

    await userEvent.click(screen.getByRole('button', { name: '2026-08-15' }))
    await userEvent.click(await screen.findByLabelText('2026年8月8日土曜日'))

    expect(onChange).not.toHaveBeenCalled()
  })

  it('switches to the year-month picker when the caption is clicked, then jumps to the chosen month', async () => {
    render(<DateRangePicker value={{ from: '2026-08-15' }} onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: '2026-08-15' }))

    await userEvent.click(screen.getByText('2026年8月'))
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
    expect(screen.getByRole('listbox', { name: '2026年の月' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('option', { name: '2026年3月' }))
    expect(await screen.findByText('2026年3月')).toBeInTheDocument()
  })
})
