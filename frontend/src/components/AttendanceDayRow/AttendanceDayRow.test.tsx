import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import type { ComponentProps } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { AttendanceDay } from '../../api/types'
import { AttendanceDayRow } from './AttendanceDayRow'

const day: AttendanceDay = {
  id: '22222222-2222-2222-2222-222222222222',
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
    late_night_prescribed_holiday_work_minutes: 0,
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

  it('shows a leave-specific label instead of 退勤済み for a full-day paid leave day', () => {
    renderRow({ day: { ...day, work_type: 'paid_leave_full' } })
    expect(screen.getByText('有給休暇(全休)')).toBeInTheDocument()
    expect(screen.queryByText('退勤済み')).not.toBeInTheDocument()
  })

  it('shows a leave-specific label for a full-day special leave day', () => {
    renderRow({ day: { ...day, work_type: 'special_leave_full' } })
    expect(screen.getByText('特別休暇(全休)')).toBeInTheDocument()
  })

  it('shows 未入力 when there is no record for the day', () => {
    renderRow({ day: undefined })
    expect(screen.getByText('未入力')).toBeInTheDocument()
  })

  it('shows the effective legal holiday classification without an attendance record', () => {
    renderRow({
      day: undefined,
      schedule: {
        id: null,
        user_id: day.user_id,
        work_date: day.work_date,
        work_style_id: 'work-style-1',
        shift_pattern_id: null,
        day_type: 'legal_holiday',
        is_working_day: false,
        is_legal_holiday: true,
        is_company_holiday: false,
        planned_start_at: null,
        planned_end_at: null,
        planned_break_minutes: 0,
        planned_break_start_at: null,
        planned_break_end_at: null,
        is_published: true,
        is_manually_overridden: false,
        schedule_source: 'company_calendar',
      },
    })

    expect(screen.getByText('法定休日')).toBeInTheDocument()
  })

  it('shows the holiday name while preserving the legal-holiday classification', () => {
    renderRow({
      day: undefined,
      schedule: {
        id: null,
        user_id: day.user_id,
        work_date: day.work_date,
        work_style_id: 'work-style-1',
        shift_pattern_id: null,
        day_type: 'legal_holiday',
        is_working_day: false,
        is_legal_holiday: true,
        is_company_holiday: false,
        is_public_holiday: true,
        public_holiday_name: '山の日',
        planned_start_at: null,
        planned_end_at: null,
        planned_break_minutes: 0,
        planned_break_start_at: null,
        planned_break_end_at: null,
        is_published: true,
        is_manually_overridden: false,
      },
    })

    expect(screen.getByText('法定休日')).toBeInTheDocument()
    expect(screen.getByText('山の日')).toBeInTheDocument()
    expect(screen.queryByText('所定休日')).not.toBeInTheDocument()
  })

  it('shows extra warning badges', () => {
    renderRow({ day: undefined, warnings: ['打刻漏れ'] })
    expect(screen.getByText('打刻漏れ')).toBeInTheDocument()
  })

  it('uses a mobile grid that separates the primary and supplementary day information', () => {
    renderRow({ day: calculatedDay, warnings: ['打刻漏れ'] })

    const link = screen.getByRole('link', { name: /2026-07-06/ })
    expect(link).toHaveClass('grid', 'grid-cols-[minmax(0,1fr)_auto]', 'sm:flex')
    expect(screen.getByText('09:00 〜 18:00').parentElement).toHaveClass('col-start-1', 'sm:contents')
  })

  it('offers request links to paid/special/compensatory leave from the kebab menu', async () => {
    renderRow()

    await userEvent.click(screen.getByRole('button', { name: '2026-07-06の操作' }))

    expect(await screen.findByRole('menuitem', { name: '有給休暇を申請する' })).toHaveAttribute(
      'href',
      '/paid-leave?date=2026-07-06',
    )
    expect(screen.getByRole('menuitem', { name: '特別休暇を申請する' })).toHaveAttribute(
      'href',
      '/special-leave?date=2026-07-06',
    )
    expect(screen.getByRole('menuitem', { name: '代休を申請する' })).toHaveAttribute(
      'href',
      '/compensatory-leave?date=2026-07-06',
    )
  })

  it('does not show a cancel item when there is no approved leave that day', async () => {
    renderRow({ onRequestCancelApprovedLeave: vi.fn() })

    await userEvent.click(screen.getByRole('button', { name: '2026-07-06の操作' }))

    expect(await screen.findByRole('menuitem', { name: '有給休暇を申請する' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '有給休暇の承認を取り消す' })).not.toBeInTheDocument()
  })

  it('calls onRequestCancelApprovedLeave when an approved-leave cancel item is selected', async () => {
    const onRequestCancelApprovedLeave = vi.fn()
    renderRow({
      approvedPaidLeaveRequestId: 'paid-leave-request-1',
      approvedSpecialLeaveRequestId: 'special-leave-request-1',
      approvedCompensatoryLeaveRequestId: 'compensatory-leave-request-1',
      onRequestCancelApprovedLeave,
    })

    await userEvent.click(screen.getByRole('button', { name: '2026-07-06の操作' }))
    await userEvent.click(await screen.findByRole('menuitem', { name: '特別休暇の承認を取り消す' }))

    expect(onRequestCancelApprovedLeave).toHaveBeenCalledWith({
      kind: 'special',
      id: 'special-leave-request-1',
      label: '特別休暇',
    })
  })
})
