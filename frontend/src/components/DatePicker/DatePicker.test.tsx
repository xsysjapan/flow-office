import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../utils/weekDates'
import { DatePicker } from './DatePicker'

function ControlledDatePicker({ onChange }: { onChange: (date: string | undefined) => void }) {
  const [value, setValue] = useState<string | undefined>(undefined)

  return (
    <DatePicker
      id="target-date"
      value={value}
      onChange={(date) => {
        setValue(date)
        onChange(date)
      }}
    />
  )
}

describe('DatePicker', () => {
  it('shows the placeholder when no date is selected', () => {
    render(<DatePicker value={undefined} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '日付を選択' })).toBeInTheDocument()
  })

  it('shows the selected date as the trigger label', () => {
    render(<DatePicker value="2026-08-15" onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '2026-08-15' })).toBeInTheDocument()
  })

  it('opens the calendar, selects a day, and reports it in YYYY-MM-DD form', async () => {
    const onChange = vi.fn()
    render(<ControlledDatePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))
    await userEvent.click(await screen.findByText('15'))

    expect(onChange).toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}-15$/))
  })

  it('closes the calendar popover after selecting a day', async () => {
    render(<ControlledDatePicker onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))
    await userEvent.click(await screen.findByText('15'))

    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('selects today via the 今日 relative-date shortcut and closes the popover', async () => {
    const onChange = vi.fn()
    render(<ControlledDatePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))
    await userEvent.click(await screen.findByRole('button', { name: '今日' }))

    expect(onChange).toHaveBeenCalledWith(formatDate(new Date()))
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('selects tomorrow via the 明日 relative-date shortcut', async () => {
    const onChange = vi.fn()
    render(<ControlledDatePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))
    await userEvent.click(await screen.findByRole('button', { name: '明日' }))

    const tomorrow = new Date()
    tomorrow.setDate(tomorrow.getDate() + 1)
    expect(onChange).toHaveBeenCalledWith(formatDate(tomorrow))
  })

  it('hides the relative-date shortcuts when showRelativeShortcuts is false', async () => {
    render(<DatePicker value={undefined} onChange={vi.fn()} showRelativeShortcuts={false} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))

    expect(await screen.findByRole('grid')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '今日' })).not.toBeInTheDocument()
  })
})
