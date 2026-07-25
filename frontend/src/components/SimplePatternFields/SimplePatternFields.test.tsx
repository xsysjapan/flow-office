import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { pickTime } from '../../test-support/pickerInteractions'
import {
  buildWeeklyPatternFromSimpleState,
  crossesMidnight,
  defaultSimplePatternState,
  SimplePatternFields,
} from './SimplePatternFields'

describe('SimplePatternFields', () => {
  it('defaults to weekdays selected with a lunch break', () => {
    const pattern = buildWeeklyPatternFromSimpleState(defaultSimplePatternState())

    expect(pattern[1]).toEqual({ start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' })
    expect(pattern[6]).toBeNull()
    expect(pattern[7]).toBeNull()
  })

  it('applies the same start/end/break to every selected weekday', () => {
    const state = { ...defaultSimplePatternState(), weekdays: { 1: true, 2: false, 3: false, 4: false, 5: false, 6: true, 7: false } }
    const pattern = buildWeeklyPatternFromSimpleState(state)

    expect(pattern[1]).toEqual(pattern[6])
    expect(pattern[2]).toBeNull()
  })

  it('treats an end time at or before the start time as crossing midnight', () => {
    expect(crossesMidnight({ startTime: '22:00', endTime: '06:00' })).toBe(true)
    expect(crossesMidnight({ startTime: '09:00', endTime: '18:00' })).toBe(false)
  })

  it('shows a 翌日 badge only when the end time crosses midnight', () => {
    render(<SimplePatternFields state={defaultSimplePatternState()} onChange={vi.fn()} />)
    expect(screen.queryByText('翌日')).not.toBeInTheDocument()

    render(
      <SimplePatternFields
        state={{ ...defaultSimplePatternState(), startTime: '22:00', endTime: '06:00' }}
        onChange={vi.fn()}
      />,
    )
    expect(screen.getByText('翌日')).toBeInTheDocument()
  })

  it('notifies onChange when the start/end time is edited', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(<SimplePatternFields state={defaultSimplePatternState()} onChange={onChange} />)

    await pickTime(user, '開始時刻', '22:00')
    expect(onChange).toHaveBeenCalledWith({ startTime: '22:00' })
  })

  it('notifies onChange when a weekday checkbox is toggled', async () => {
    const onChange = vi.fn()
    render(<SimplePatternFields state={defaultSimplePatternState()} onChange={onChange} />)

    await userEvent.click(screen.getByRole('checkbox', { name: '土' }))
    expect(onChange).toHaveBeenCalledWith({ weekdays: expect.objectContaining({ 6: true }) })
  })
})
