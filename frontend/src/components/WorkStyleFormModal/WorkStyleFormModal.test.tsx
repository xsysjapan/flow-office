import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as workCalendarsApi from '../../api/workCalendars'
import * as workStylesApi from '../../api/workStyles'
import type { WorkCalendar, WorkStyle } from '../../api/types'
import { WorkStyleFormModal } from './WorkStyleFormModal'
import { notify } from '../../lib/notify'
import { pickDate, pickTime } from '../../test-support/pickerInteractions'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

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
  allows_daily_holiday_override: true,
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
  is_system_generated: true,
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
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('creates a new work style with the entered values', async () => {
    vi.spyOn(workStylesApi, 'createWorkStyle').mockResolvedValue({ ...workStyle, id: 'work-style-2', code: 'discretionary' })
    renderModal()

    expect(await screen.findByRole('heading', { name: '勤務形態を作成' })).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('コード'), 'discretionary')
    await userEvent.type(screen.getByLabelText('名称'), '裁量労働制勤務')
    await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
    await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
    await userEvent.type(screen.getByLabelText('みなし労働時間(分/日)'), '540')
    await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

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
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('働き方「裁量労働制勤務」を作成しました'))
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
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    await waitFor(() =>
      expect(workStylesApi.updateWorkStyle).toHaveBeenCalledWith(
        'work-style-1',
        expect.objectContaining({ code: 'standard', name: '標準勤務(改)' }),
      ),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('働き方「標準勤務(改)」を保存しました'))
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
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    await waitFor(() =>
      expect(workStylesApi.createWorkStyle).toHaveBeenCalledWith(
        expect.objectContaining({ rounding_unit_minutes: 15, rounding_mode: 'shorten' }),
      ),
    )
  }, 15000)

  describe('422 field errors', () => {
    const COMMON = {
      code: 'コードのエラー',
      name: '名称のエラー',
      work_time_system: '労働時間制のエラー',
      prescribed_daily_minutes: '日の所定のエラー',
      prescribed_weekly_minutes: '週の所定のエラー',
      workday_boundary_type: '作業日区切りのエラー',
      default_start_time: '標準開始のエラー',
      default_end_time: '標準終了のエラー',
      default_break_minutes: '標準休憩のエラー',
      default_break_start_time: '休憩開始のエラー',
      default_break_end_time: '休憩終了のエラー',
      rounding_unit_minutes: '丸め単位のエラー',
      company_calendar_id: 'カレンダーのエラー',
      auto_break_enabled: '自動休憩のエラー',
      is_shift_based: 'シフト制のエラー',
    }

    const unprocessable = (errors: Record<string, string>) =>
      new ApiError(
        422,
        '最初のエラーの文です。',
        Object.fromEntries([...Object.entries(errors), ['unknown_key', 'その他のエラー']].map(([k, v]) => [k, [v]])),
      )

    async function expectEachOnce(messages: string[]) {
      for (const message of [...messages, 'その他のエラー']) {
        expect(await screen.findAllByText(message)).toHaveLength(1)
      }
      expect(screen.getAllByText('入力内容を確認してください。')).toHaveLength(1)
      expect(screen.queryByText('最初のエラーの文です。')).not.toBeInTheDocument()
    }

    async function fillRequired() {
      await userEvent.type(await screen.findByLabelText('コード'), 'c')
      await userEvent.type(screen.getByLabelText('名称'), 'n')
      await userEvent.type(screen.getByLabelText('所定労働時間(分/日)'), '480')
      await userEvent.type(screen.getByLabelText('所定労働時間(分/週)'), '2400')
      await userEvent.selectOptions(screen.getByLabelText('カレンダー'), '2026年度カレンダー')
    }

    it('shows the errors of the always-shown fields, the custom boundary time and the shift-based fields', async () => {
      const errors = {
        ...COMMON,
        workday_boundary_time: '作業日開始時刻のエラー',
        legal_holiday_rule: '法定休日のエラー',
        four_week_period_start_date: '4週起算日のエラー',
        max_consecutive_work_days: '連続勤務のエラー',
        // 描いていない項目(裁量・フレックス)のキーは、項目に出ずまとめての表示に残る。
        deemed_daily_minutes: 'みなしのエラー',
      }
      vi.spyOn(workStylesApi, 'createWorkStyle').mockRejectedValue(unprocessable(errors))
      renderModal()
      await fillRequired()
      await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '通常勤務')
      await userEvent.selectOptions(screen.getByLabelText('作業日の区切り'), '指定時刻基準')
      await pickTime(userEvent.setup(), '作業日の開始時刻', '05:00')
      await userEvent.click(screen.getByLabelText('シフト制'))
      await userEvent.selectOptions(screen.getByLabelText('法定休日の与え方'), '4週4日以上(変形休日制)')
      await pickDate(userEvent.setup(), '4週間の起算日', '2026-04-01')
      await userEvent.click(screen.getByRole('button', { name: '作成' }))

      await expectEachOnce(Object.values(errors))
    }, 20000)

    it('shows the discretionary field error under the field when discretionary', async () => {
      const errors = { ...COMMON, deemed_daily_minutes: 'みなしのエラー' }
      vi.spyOn(workStylesApi, 'createWorkStyle').mockRejectedValue(unprocessable(errors))
      renderModal()
      await fillRequired()
      await userEvent.selectOptions(screen.getByLabelText('労働時間制'), '裁量労働制')
      await userEvent.type(screen.getByLabelText('みなし労働時間(分/日)'), '540')
      await userEvent.click(screen.getByRole('button', { name: '作成' }))

      await expectEachOnce(Object.values(errors))
    }, 20000)

    it('shows the flex and core time errors under the fields when flex with core time', async () => {
      const errors = {
        ...COMMON,
        settlement_start_day: '清算起算日のエラー',
        flexible_time_start: '勤務可能開始のエラー',
        flexible_time_end: '勤務可能終了のエラー',
        core_time_enabled: 'コアタイム有効のエラー',
        core_time_start: 'コア開始のエラー',
        core_time_end: 'コア終了のエラー',
      }
      vi.spyOn(workStylesApi, 'createWorkStyle').mockRejectedValue(unprocessable(errors))
      renderModal()
      await fillRequired()
      await userEvent.selectOptions(screen.getByLabelText('労働時間制'), 'フレックスタイム制')
      await userEvent.click(screen.getByLabelText('コアタイムあり'))
      await pickTime(userEvent.setup(), 'コアタイム開始時刻', '10:00')
      await pickTime(userEvent.setup(), 'コアタイム終了時刻', '15:00')
      await userEvent.click(screen.getByRole('button', { name: '作成' }))

      await expectEachOnce(Object.values(errors))
    }, 20000)
  })
})
