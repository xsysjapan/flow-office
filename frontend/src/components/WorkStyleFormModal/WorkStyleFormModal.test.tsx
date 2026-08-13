import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import * as workStylesApi from '../../api/workStyles'
import type { WorkCalendar, WorkStyle } from '../../api/types'
import { WorkStyleFormModal } from './WorkStyleFormModal'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
  allow_daily_holiday_override: true,
}

const workStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '標準勤務',
  work_time_system: 'fixed',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  deemed_daily_minutes: null,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  company_calendar_id: 'calendar-1',
  is_shift_based: false,
  is_default: true,
  system_generated: true,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  applied_employee_count: 3,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

function renderModal(props: Partial<React.ComponentProps<typeof WorkStyleFormModal>> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([calendar])
  const onOpenChange = vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <WorkStyleFormModal mode="create" open onOpenChange={onOpenChange} {...props} />
    </QueryClientProvider>,
  )

  return { onOpenChange }
}

describe('WorkStyleFormModal', () => {
  it('creates a new work style with the entered values', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-2', code: 'discretionary' })
    renderModal()

    expect(await screen.findByRole('heading', { name: '勤務形態を登録' })).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('コード'), 'discretionary')
    await userEvent.type(screen.getByLabelText('名称'), '裁量労働制勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.type(screen.getByLabelText('みなし労働時間(分/日)'), '540')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({
          code: 'discretionary',
          name: '裁量労働制勤務',
          work_time_system: 'discretionary',
          deemed_daily_minutes: 540,
        }),
      ),
    )
  }, 15000)

  it('shows the deemed-minutes field only for discretionary work styles', async () => {
    renderModal()

    expect(screen.queryByLabelText('みなし労働時間(分/日)')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
    expect(screen.getByLabelText('みなし労働時間(分/日)')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '通常勤務')
    expect(screen.queryByLabelText('みなし労働時間(分/日)')).not.toBeInTheDocument()
  })

  it('pre-fills the form with the existing work style in edit mode and updates it', async () => {
    vi.spyOn(workStylesApi, 'updateWorkStyle').mockResolvedValue({ ...workStyle, name: '標準勤務(改)' })
    renderModal({ mode: 'edit', workStyle })

    expect(await screen.findByRole('heading', { name: '勤務形態を編集(標準勤務)' })).toBeInTheDocument()
    const nameField = screen.getByLabelText('名称')
    expect(nameField).toHaveValue('標準勤務')

    await userEvent.clear(nameField)
    await userEvent.type(nameField, '標準勤務(改)')
    await userEvent.click(screen.getByRole('button', { name: '更新する' }))

    await waitFor(() =>
      expect(workStylesApi.updateWorkStyle).toHaveBeenCalledWith(
        'work-style-1',
        expect.objectContaining({ code: 'standard', name: '標準勤務(改)' }),
      ),
    )
  }, 15000)

  it('calls onOpenChange(false) when cancel is clicked', async () => {
    const { onOpenChange } = renderModal()

    await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('combines the rounding unit and direction into a single field', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-2', code: 'rounded' })
    renderModal()

    await userEvent.type(await screen.findByLabelText('コード'), 'rounded')
    await userEvent.type(screen.getByLabelText('名称'), '丸めテスト勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '通常勤務')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.selectOptions(
      screen.getByLabelText('打刻の丸め単位'),
      '15分(切り捨て(勤務時間が短くなる方向))',
    )
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({ rounding_unit_minutes: 15, rounding_mode: 'shorten' }),
      ),
    )
  }, 15000)
})
