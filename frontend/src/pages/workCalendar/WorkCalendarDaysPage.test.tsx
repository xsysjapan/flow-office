import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import type { WorkCalendar, WorkCalendarDay, WorkCalendarYear } from '../../api/types'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
  weekday_holiday_pattern: {
    '1': 'working',
    '2': 'working',
    '3': 'working',
    '4': 'working',
    '5': 'working',
    '6': 'company_holiday',
    '7': 'legal_holiday',
  },
  allow_daily_holiday_override: true,
}

const year: WorkCalendarYear = {
  id: 'year-1',
  company_calendar_id: 'calendar-1',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2026-04-30',
  status: 'draft',
  generated_from: 'manual',
  published_at: null,
  published_by_user_id: null,
}

/** 2026-04(30日)を全てWORKで埋めた既存データ。 */
function buildAprilDays(): WorkCalendarDay[] {
  return Array.from({ length: 30 }, (_, i) => {
    const date = `2026-04-${String(i + 1).padStart(2, '0')}`
    return {
      id: i + 1,
      date,
      day_type: 'weekday',
      is_working_day: true,
      is_legal_holiday: false,
      is_company_holiday: false,
      is_public_holiday: false,
      public_holiday_name: null,
      schedule_state: 'WORK' as const,
      note: null,
    }
  })
}

function renderPage(calendars: WorkCalendar[] = [calendar], years: WorkCalendarYear[] = [year]) {
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue(calendars)
  vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue(years)

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/years/2026/days']}>
        <Routes>
          <Route path="/admin/work-calendars/:calendarId/years/:fiscalYear/days" element={<WorkCalendarDaysPage />} />
          <Route path="/admin/work-calendars/:id" element={<p>カレンダー詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkCalendarDaysPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads existing days and renders them in the grid with correct status coloring', async () => {
    const days = buildAprilDays()
    days[3] = {
      ...days[3],
      schedule_state: 'OFF',
      is_working_day: false,
      is_company_holiday: true,
      is_public_holiday: true,
      public_holiday_name: '昭和の日改め',
    }
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(days)

    renderPage()

    expect(await screen.findByText('2026年度')).toBeInTheDocument()
    expect(screen.getByText('2026-04-01〜2026-04-30')).toBeInTheDocument()

    const workCell = await screen.findByRole('button', { name: '2026-04-01 勤務日' })
    expect(workCell).toBeInTheDocument()

    const holidayCell = screen.getByRole('button', { name: '2026-04-04 休日(昭和の日改め)' })
    expect(holidayCell).toBeInTheDocument()

    expect(await screen.findByText('29日')).toBeInTheDocument()
  })

  it('edits a day (toggle to OFF, mark a public holiday) and recomputes stats, then saves', async () => {
    const days = buildAprilDays()
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(days)
    vi.spyOn(workCalendarsApi, 'putWorkCalendarDays').mockResolvedValue([])

    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText('30日')).toBeInTheDocument()
    expect(screen.getByText('240時間')).toBeInTheDocument()

    await user.click(await screen.findByRole('button', { name: '2026-04-05 勤務日' }))
    await user.selectOptions(screen.getByLabelText('2026-04-05の勤務区分'), 'company_holiday')
    await user.click(screen.getByLabelText('2026-04-05の休日'))
    await user.type(screen.getByLabelText('2026-04-05の休日名'), 'こどもの日')
    await user.keyboard('{Escape}')

    expect(await screen.findByText('29日')).toBeInTheDocument()
    expect(screen.getByText('232時間')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() => expect(workCalendarsApi.putWorkCalendarDays).toHaveBeenCalled())
    const [, savedDays] = vi.mocked(workCalendarsApi.putWorkCalendarDays).mock.calls[0]
    const edited = savedDays.find((d) => d.date === '2026-04-05')
    expect(edited).toEqual({
      date: '2026-04-05',
      day_type: 'public_holiday',
      schedule_state: 'OFF',
      is_working_day: false,
      is_legal_holiday: false,
      is_company_holiday: true,
      is_public_holiday: true,
      public_holiday_name: 'こどもの日',
      note: undefined,
    })
  })

  it('shows the classification read-only and hints at the lock when the calendar disallows daily override', async () => {
    const lockedCalendar: WorkCalendar = { ...calendar, allow_daily_holiday_override: false }
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendars').mockResolvedValue([lockedCalendar])
    vi.spyOn(workCalendarsApi, 'fetchWorkCalendarYears').mockResolvedValue([year])
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())

    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/years/2026/days']}>
          <Routes>
            <Route path="/admin/work-calendars/:calendarId/years/:fiscalYear/days" element={<WorkCalendarDaysPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await userEvent.click(await screen.findByRole('button', { name: '2026-04-01 勤務日' }))

    expect(screen.queryByLabelText('2026-04-01の勤務区分')).not.toBeInTheDocument()
    expect(
      await screen.findByText('曜日ごとの休日設定に従います(会社カレンダーの設定でロックされています)。'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('このカレンダーは曜日ごとの休日設定の日別変更がロックされています。日別の勤務区分を変更したい場合は、カレンダー本体の設定でロックを解除するか、この年度を再作成してください。'),
    ).toBeInTheDocument()

    // 祝日・メモはロック中でも編集できる。
    expect(screen.getByLabelText('2026-04-01の休日')).toBeEnabled()
  })

  it('shows an empty grid without blocking when the year has no days yet', async () => {
    vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue([])

    renderPage()

    const cell = await screen.findByRole('button', { name: '2026-04-01 勤務日' })
    expect(within(cell).getByText('1')).toBeInTheDocument()
  })

  describe('年度アクション(旧WorkCalendarDetailPageの年度一覧行から移設)', () => {
    it('publishes a draft year when the publish button is clicked', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'publishWorkCalendarYear').mockResolvedValue({ ...year, status: 'published' })

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '公開する' }))

      await waitFor(() => expect(workCalendarsApi.publishWorkCalendarYear).toHaveBeenCalledWith('year-1'))
    })

    it('unpublishes a published year when the unpublish button is clicked', async () => {
      const publishedYear: WorkCalendarYear = { ...year, status: 'published' }
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'unpublishWorkCalendarYear').mockResolvedValue({ ...publishedYear, status: 'draft' })

      renderPage([calendar], [publishedYear])

      await userEvent.click(await screen.findByRole('button', { name: '公開を取消す' }))

      await waitFor(() => expect(workCalendarsApi.unpublishWorkCalendarYear).toHaveBeenCalledWith('year-1'))
    })

    it('deletes a year when the delete button is confirmed, then navigates back to the calendar', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'deleteWorkCalendarYear').mockResolvedValue(undefined)

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '削除' }))
      expect(await screen.findByText('「2026年度」を削除しますか?')).toBeInTheDocument()
      await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

      await waitFor(() => expect(workCalendarsApi.deleteWorkCalendarYear).toHaveBeenCalledWith('year-1'))
      expect(await screen.findByText('カレンダー詳細ページ')).toBeInTheDocument()
    })

    it('does not delete a year when the confirmation is dismissed', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'deleteWorkCalendarYear').mockResolvedValue(undefined)

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '削除' }))
      await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

      expect(workCalendarsApi.deleteWorkCalendarYear).not.toHaveBeenCalled()
    })

    it('duplicates a year when the duplicate button is clicked', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'duplicateWorkCalendarYear').mockResolvedValue({
        ...year,
        id: 'year-2',
        fiscal_year: 2027,
      })

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '複製して翌年度を作成' }))

      await waitFor(() => expect(workCalendarsApi.duplicateWorkCalendarYear).toHaveBeenCalledWith('year-1'))
    })

    it('regenerates a draft year from the calendar weekday pattern after confirming', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays')
        .mockResolvedValueOnce(buildAprilDays())
        .mockResolvedValue(buildAprilDays())
      const regenerated = buildAprilDays()
      vi.spyOn(workCalendarsApi, 'regenerateCompanyCalendarYear').mockResolvedValue(regenerated)

      renderPage()

      const regenerateButton = await screen.findByRole('button', { name: '年度を再作成する' })
      expect(regenerateButton).toBeEnabled()
      await userEvent.click(regenerateButton)
      await userEvent.click(await screen.findByRole('button', { name: '作り直す' }))

      await waitFor(() =>
        expect(workCalendarsApi.regenerateCompanyCalendarYear).toHaveBeenCalledWith('year-1'),
      )
    })

    it('does not regenerate when the confirmation is declined', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'regenerateCompanyCalendarYear').mockResolvedValue(buildAprilDays())

      renderPage()

      await userEvent.click(await screen.findByRole('button', { name: '年度を再作成する' }))
      await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

      expect(workCalendarsApi.regenerateCompanyCalendarYear).not.toHaveBeenCalled()
    })

    it('hides the regenerate button for a published year', async () => {
      const publishedYear: WorkCalendarYear = { ...year, status: 'published' }
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())

      renderPage([calendar], [publishedYear])

      await screen.findByText('公開済み・廃止済みの年度は再作成できません(未公開の年度のみ再作成できます)。')
      expect(screen.queryByRole('button', { name: '年度を再作成する' })).not.toBeInTheDocument()
    })

    it('disables the sync button and hints at source management when no source is set', async () => {
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())

      renderPage()

      expect(await screen.findByRole('button', { name: 'この年度を休日と同期する' })).toBeDisabled()
      expect(screen.getByRole('link', { name: '休日iCalendarソース管理' })).toBeInTheDocument()
    })

    it('syncs the year holiday calendar and shows the reflected summary', async () => {
      const calendarWithSource = { ...calendar, holiday_calendar_source_id: 'source-1' }
      const syncedSource = {
        id: 'source-1',
        name: '内閣府祝日カレンダー',
        source_kind: 'url' as const,
        ics_url: 'https://example.com/holidays.ics',
        uploaded_ics_filename: null,
        sync_status: 'synced' as const,
        last_synced_at: '2026-08-12T00:00:00+09:00',
        last_error: null,
        disabled_at: null,
        last_sync_summary: { added: 2, updated: 0, removed: 1, applied: 2, protected_conflicts: 0 },
      }
      vi.spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays').mockResolvedValue(buildAprilDays())
      vi.spyOn(workCalendarsApi, 'syncCompanyCalendarYearHolidayCalendar').mockResolvedValue(syncedSource)

      renderPage([calendarWithSource])

      const syncButton = await screen.findByRole('button', { name: 'この年度を休日と同期する' })
      expect(syncButton).toBeEnabled()
      await userEvent.click(syncButton)

      await waitFor(() =>
        expect(workCalendarsApi.syncCompanyCalendarYearHolidayCalendar).toHaveBeenCalledWith('year-1'),
      )

      expect(
        await screen.findByText('追加 2件・更新 0件・削除 1件・カレンダーに反映 2件(手動変更保護のためスキップ 0件)'),
      ).toBeInTheDocument()
    })

    /**
     * 同期はサーバ側の日別データ(祝日フラグ・祝日名)を書き換えるため、同期後はグリッドが
     * 再読み込みされて同期結果を反映していなければならない。これが無いと、画面には同期前の
     * 内容が残ったままになり、その状態で「保存する」を押すと同期結果を古い内容で
     * 上書きしてしまう(データ消失)。
     */
    it('reloads the grid from the server after a sync so the synced holidays are not overwritten on save', async () => {
      const calendarWithSource = { ...calendar, holiday_calendar_source_id: 'source-1' }
      const syncedSource = {
        id: 'source-1',
        name: '内閣府祝日カレンダー',
        source_kind: 'url' as const,
        ics_url: 'https://example.com/holidays.ics',
        uploaded_ics_filename: null,
        sync_status: 'synced' as const,
        last_synced_at: '2026-08-12T00:00:00+09:00',
        last_error: null,
        disabled_at: null,
        last_sync_summary: { added: 1, updated: 0, removed: 0, applied: 1, protected_conflicts: 0 },
      }

      // 同期後は 2026-04-29 が祝日として反映された状態がサーバから返る。
      const daysAfterSync = buildAprilDays()
      daysAfterSync[28] = {
        ...daysAfterSync[28],
        schedule_state: 'OFF',
        is_working_day: false,
        is_public_holiday: true,
        public_holiday_name: '昭和の日',
      }

      const fetchDays = vi
        .spyOn(workCalendarsApi, 'fetchCompanyCalendarYearDays')
        .mockResolvedValueOnce(buildAprilDays())
        .mockResolvedValue(daysAfterSync)
      vi.spyOn(workCalendarsApi, 'syncCompanyCalendarYearHolidayCalendar').mockResolvedValue(syncedSource)
      vi.spyOn(workCalendarsApi, 'putWorkCalendarDays').mockResolvedValue([])

      renderPage([calendarWithSource])

      // 同期前は全日勤務日(祝日セルは存在しない)。
      expect(await screen.findByRole('button', { name: '2026-04-29 勤務日' })).toBeInTheDocument()

      await userEvent.click(await screen.findByRole('button', { name: 'この年度を休日と同期する' }))

      // 同期後、グリッドが再取得され祝日が反映されている。
      expect(await screen.findByRole('button', { name: '2026-04-29 休日(昭和の日)' })).toBeInTheDocument()
      expect(fetchDays.mock.calls.length).toBeGreaterThan(1)

      // その状態で保存すると、同期結果(祝日)が保持されたまま送信される。
      await userEvent.click(screen.getByRole('button', { name: '保存する' }))

      await waitFor(() => expect(workCalendarsApi.putWorkCalendarDays).toHaveBeenCalled())

      const savedDays = vi.mocked(workCalendarsApi.putWorkCalendarDays).mock.calls[0][1]
      const savedApril29 = savedDays.find((d) => d.date === '2026-04-29')
      expect(savedApril29?.is_public_holiday).toBe(true)
      expect(savedApril29?.public_holiday_name).toBe('昭和の日')
    })
  })
})
