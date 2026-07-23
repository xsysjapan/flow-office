import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { ComponentProps } from 'react'
import { describe, expect, it } from 'vitest'
import type { AttendanceDay } from '../../api/types'
import { AttendanceDayRow } from './AttendanceDayRow'

const day: AttendanceDay = {
  id: 1,
  user_id: '11111111-1111-1111-1111-111111111111',
  work_date: '2026-07-06',
  status: 'clocked_out',
  actual_start_at: '2026-07-06T09:00:00+09:00',
  actual_end_at: '2026-07-06T18:00:00+09:00',
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

const calculatedDay: AttendanceDay = {
  ...day,
  calculation: {
    planned_work_minutes: 480,
    work_minutes: 480,
    prescribed_work_minutes: 480,
    statutory_within_overtime_minutes: 0,
    statutory_excess_overtime_minutes: 0,
    late_night_work_minutes: 0,
    late_night_prescribed_work_minutes: 0,
    late_night_statutory_within_overtime_minutes: 0,
    late_night_statutory_excess_overtime_minutes: 0,
    legal_holiday_work_minutes: 0,
    prescribed_holiday_work_minutes: 0,
    late_night_legal_holiday_work_minutes: 0,
    core_time_violation: false,
    is_manually_adjusted: false,
  },
}

function renderRow(props: Partial<ComponentProps<typeof AttendanceDayRow>> = {}) {
  return render(
    <MemoryRouter>
      <ul>
        <AttendanceDayRow date="2026-07-06" day={day} {...props} />
      </ul>
    </MemoryRouter>,
  )
}

describe('AttendanceDayRow', () => {
  it('links to the day detail page for the given date', () => {
    renderRow()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/attendance/days/2026-07-06')
  })

  it('shows the weekday, status, and recorded times for a day with a record', () => {
    renderRow()
    expect(screen.getByText('2026-07-06(月)')).toBeInTheDocument()
    expect(screen.getByText('退勤済み')).toBeInTheDocument()
    expect(screen.getByText('09:00 〜 18:00')).toBeInTheDocument()
  })

  it('shows labor time when calculation exists', () => {
    renderRow({ day: calculatedDay })
    expect(screen.getByText('8時間')).toBeInTheDocument()
  })

  it('shows 未入力 when there is no record for the day', () => {
    renderRow({ day: undefined })
    expect(screen.getByText('未入力')).toBeInTheDocument()
  })

  it('shows extra warning badges', () => {
    renderRow({ day: undefined, warnings: ['打刻漏れ'] })
    expect(screen.getByText('打刻漏れ')).toBeInTheDocument()
  })

  it('uses a mobile grid that separates the primary and supplementary day information', () => {
    renderRow({ day: calculatedDay, warnings: ['打刻漏れ'] })

    const link = screen.getByRole('link')
    expect(link).toHaveClass('grid', 'grid-cols-[minmax(0,1fr)_auto]', 'sm:flex')
    expect(screen.getByText('09:00 〜 18:00').parentElement).toHaveClass('col-start-1', 'sm:contents')
  })
})
