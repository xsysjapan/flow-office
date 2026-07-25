import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { TimePicker } from './TimePicker'

function ControlledTimePicker({ onChange }: { onChange: (time: string | undefined) => void }) {
  const [value, setValue] = useState<string | undefined>(undefined)

  return (
    <TimePicker
      id="target-time"
      value={value}
      onChange={(time) => {
        setValue(time)
        onChange(time)
      }}
    />
  )
}

describe('TimePicker', () => {
  it('shows the placeholder when no time is selected', () => {
    render(<TimePicker value={undefined} onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '時刻を選択' })).toBeInTheDocument()
  })

  it('shows the selected time as the trigger label', () => {
    render(<TimePicker value="09:30" onChange={vi.fn()} />)
    expect(screen.getByRole('button', { name: '09:30' })).toBeInTheDocument()
  })

  it('opens the list and picks an hour and a minute independently', async () => {
    const onChange = vi.fn()
    render(<ControlledTimePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))

    const hourList = screen.getByRole('listbox', { name: '時' })
    const minuteList = screen.getByRole('listbox', { name: '分' })

    await userEvent.click(within(hourList).getByRole('option', { name: '09' }))
    expect(onChange).toHaveBeenLastCalledWith('09:00')

    await userEvent.click(within(minuteList).getByRole('option', { name: '30' }))
    expect(onChange).toHaveBeenLastCalledWith('09:30')
  })

  it('keeps the popover open after picking a value, so hour and minute can both be set', async () => {
    render(<ControlledTimePicker onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))
    const hourList = screen.getByRole('listbox', { name: '時' })
    await userEvent.click(within(hourList).getByRole('option', { name: '09' }))

    expect(screen.getByRole('listbox', { name: '時' })).toBeInTheDocument()
  })

  it('respects a custom minute step', async () => {
    render(<TimePicker value={undefined} onChange={vi.fn()} minuteStep={15} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))

    const minuteList = screen.getByRole('listbox', { name: '分' })
    expect(within(minuteList).getAllByRole('option')).toHaveLength(4)
  })
})
