import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../utils/weekDates'
import { DateTimePicker } from './DateTimePicker'

function ControlledDateTimePicker({ onChange }: { onChange: (value: string | undefined) => void }) {
  const [value, setValue] = useState<string | undefined>(undefined)

  return (
    <DateTimePicker
      id="target-datetime"
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
    />
  )
}

describe('DateTimePicker', () => {
  it('shows independent placeholders for the date and time parts when no value is set', () => {
    render(<DateTimePicker value={undefined} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '日付を選択' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '時刻を選択' })).toBeInTheDocument()
  })

  it('splits an existing "YYYY-MM-DDTHH:mm" value across the date and time triggers', () => {
    render(<DateTimePicker value="2026-08-15T09:30" onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '2026-08-15' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '09:30' })).toBeInTheDocument()
  })

  it('picking a date first, then a time, combines them into one "YYYY-MM-DDTHH:mm" value', async () => {
    const onChange = vi.fn()
    render(<ControlledDateTimePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '日付を選択' }))
    await userEvent.click(await screen.findByRole('button', { name: '今日' }))
    expect(onChange).toHaveBeenLastCalledWith(`${formatDate(new Date())}T00:00`)

    await userEvent.click(screen.getByRole('button', { name: '00:00' }))
    const hourList = screen.getByRole('listbox', { name: '時' })
    await userEvent.click(within(hourList).getByRole('option', { name: '09' }))
    const minuteList = screen.getByRole('listbox', { name: '分' })
    await userEvent.click(within(minuteList).getByRole('option', { name: '30' }))

    expect(onChange).toHaveBeenLastCalledWith(`${formatDate(new Date())}T09:30`)
  })

  it('picking a time first defaults the date to today', async () => {
    const onChange = vi.fn()
    render(<ControlledDateTimePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))
    const hourList = screen.getByRole('listbox', { name: '時' })
    await userEvent.click(within(hourList).getByRole('option', { name: '14' }))

    expect(onChange).toHaveBeenLastCalledWith(`${formatDate(new Date())}T14:00`)
  })
})
