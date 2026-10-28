import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { buildWeeklyPattern, defaultWeeklyPatternState, WeekdayScheduleFields } from './WeekdayScheduleFields'

describe('WeekdayScheduleFields', () => {
  it('defaults to weekdays enabled with a lunch break, weekends disabled', () => {
    const pattern = buildWeeklyPattern(defaultWeeklyPatternState())

    expect(pattern[1]).toEqual({ start_time: '09:00', end_time: '18:00', break_start_time: '12:00', break_end_time: '13:00' })
    expect(pattern[6]).toBeNull()
    expect(pattern[7]).toBeNull()
  })

  it('notifies onChange when a weekday is toggled on and its times are edited', async () => {
    const onChange = vi.fn()
    render(<WeekdayScheduleFields state={defaultWeeklyPatternState()} onChange={onChange} />)

    await userEvent.click(screen.getByRole('checkbox', { name: '土曜日' }))
    expect(onChange).toHaveBeenCalledWith(6, { enabled: true })

    await userEvent.click(screen.getByRole('checkbox', { name: '月曜日の休憩' }))
    expect(onChange).toHaveBeenCalledWith(1, { breakEnabled: false })
  })

  it('disables the time inputs for a weekday that is not enabled', () => {
    render(<WeekdayScheduleFields state={defaultWeeklyPatternState()} onChange={vi.fn()} />)

    expect(screen.getByLabelText('土曜日の出勤時刻')).toBeDisabled()
    expect(screen.getByLabelText('月曜日の出勤時刻')).toBeEnabled()
  })

  it('shows the row badges only on the matching weekday row', () => {
    render(
      <WeekdayScheduleFields
        state={defaultWeeklyPatternState()}
        onChange={vi.fn()}
        rowBadges={{ 2: [{ label: '文化の日', tone: 'neutral' }, { label: '法定休日', tone: 'danger' }] }}
      />,
    )

    expect(screen.getAllByText('文化の日')).toHaveLength(1)
    expect(screen.getAllByText('法定休日')).toHaveLength(1)
    const tuesdayRow = screen.getByRole('checkbox', { name: '火曜日' }).closest('div.rounded-md') as HTMLElement
    expect(within(tuesdayRow).getByText('文化の日')).toBeInTheDocument()
  })
})
