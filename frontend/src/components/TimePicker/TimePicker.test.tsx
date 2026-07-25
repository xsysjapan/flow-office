import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { TimePicker } from './TimePicker'

function ControlledTimePicker({
  onChange,
  initialValue,
}: {
  onChange: (time: string | undefined) => void
  initialValue?: string
}) {
  const [value, setValue] = useState<string | undefined>(initialValue)

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

  it('scrolls the selected hour and minute into view when opened', async () => {
    const scrollIntoView = vi.fn()
    Element.prototype.scrollIntoView = scrollIntoView
    render(<TimePicker value="09:30" onChange={vi.fn()} />)

    expect(scrollIntoView).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: '09:30' }))

    expect(scrollIntoView).toHaveBeenCalledTimes(2)
    expect(scrollIntoView).toHaveBeenNthCalledWith(1, { block: 'center' })
    expect(scrollIntoView).toHaveBeenNthCalledWith(2, { block: 'center' })
    expect(within(screen.getByRole('listbox', { name: '時' })).getByRole('option', { name: '09' })).toHaveAttribute(
      'aria-selected',
      'true',
    )
    expect(within(screen.getByRole('listbox', { name: '分' })).getByRole('option', { name: '30' })).toHaveAttribute(
      'aria-selected',
      'true',
    )
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

  it('clears the selected time and closes the popover', async () => {
    const onChange = vi.fn()
    render(<ControlledTimePicker initialValue="09:30" onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '09:30' }))
    const clearButton = screen.getByRole('button', { name: 'クリア' })
    expect(clearButton.parentElement).toHaveClass('border-b', 'bg-muted/30')
    await userEvent.click(clearButton)

    expect(onChange).toHaveBeenCalledWith(undefined)
    expect(screen.getByRole('button', { name: '時刻を選択' })).toBeInTheDocument()
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('shows the current-time shortcut but not the clear button when no time is selected', async () => {
    render(<TimePicker value={undefined} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))

    expect(screen.getByRole('button', { name: '現在時刻' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'クリア' })).not.toBeInTheDocument()
  })

  it('selects the current time and closes the popover', async () => {
    const onChange = vi.fn()
    render(<ControlledTimePicker onChange={onChange} />)

    await userEvent.click(screen.getByRole('button', { name: '時刻を選択' }))
    const now = new Date()
    await userEvent.click(screen.getByRole('button', { name: '現在時刻' }))

    expect(onChange).toHaveBeenCalledWith(
      `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`,
    )
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('keeps the fixed width when current-time and clear actions are both visible', async () => {
    render(<TimePicker value="09:30" onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: '09:30' }))

    expect(screen.getByRole('dialog')).toHaveClass('w-40')
    expect(screen.getByRole('button', { name: '現在時刻' })).toHaveClass('text-sm', 'font-medium')
    expect(screen.getByRole('button', { name: 'クリア' })).toHaveClass('text-sm', 'font-medium')
  })

  it('keeps each option from shrinking so both columns remain scrollable', async () => {
    render(<TimePicker value={undefined} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: /選択/ }))

    const hourList = screen.getByRole('listbox', { name: '時' })
    const minuteList = screen.getByRole('listbox', { name: '分' })
    expect(within(hourList).getAllByRole('option')[0]).toHaveClass('shrink-0')
    expect(within(minuteList).getAllByRole('option')[0]).toHaveClass('shrink-0')
  })
})
