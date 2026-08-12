import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as holidayCalendarSourcesApi from '../../api/holidayCalendarSources'
import type { HolidayCalendarSource } from '../../api/types'
import { HolidayCalendarSourcesPage } from './HolidayCalendarSourcesPage'

const source: HolidayCalendarSource = {
  id: 'source-1',
  name: '内閣府祝日カレンダー',
  ics_url: 'https://example.com/holidays.ics',
  sync_status: 'pending',
  last_synced_at: null,
  last_error: null,
  disabled_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <HolidayCalendarSourcesPage />
    </QueryClientProvider>,
  )
}

describe('HolidayCalendarSourcesPage', () => {
  it('shows an empty state before anything is registered', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources').mockResolvedValue([])

    renderPage()

    expect(await screen.findByText(/まだ登録されていません/)).toBeInTheDocument()
  })

  it('registers a source and shows it in the list', async () => {
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([])
      .mockResolvedValue([source])
    vi.spyOn(holidayCalendarSourcesApi, 'createHolidayCalendarSource').mockResolvedValue(source)

    renderPage()
    await screen.findByText(/まだ登録されていません/)

    await userEvent.type(screen.getByLabelText('名称'), '内閣府祝日カレンダー')
    await userEvent.type(screen.getByLabelText('iCalendar URL'), 'https://example.com/holidays.ics')
    await userEvent.click(screen.getByRole('button', { name: '登録する' }))

    await waitFor(() =>
      expect(holidayCalendarSourcesApi.createHolidayCalendarSource).toHaveBeenCalledWith({
        name: '内閣府祝日カレンダー',
        ics_url: 'https://example.com/holidays.ics',
      }),
    )
    expect(await screen.findByText('内閣府祝日カレンダー')).toBeInTheDocument()
    expect(screen.getByText('未同期')).toBeInTheDocument()
  })

  it('syncs, reverts the last sync, and then disables a registered source', async () => {
    const synced: HolidayCalendarSource = {
      ...source,
      sync_status: 'synced',
      last_synced_at: '2026-08-11T00:00:00+09:00',
    }
    vi.spyOn(holidayCalendarSourcesApi, 'fetchHolidayCalendarSources')
      .mockResolvedValueOnce([source])
      .mockResolvedValueOnce([synced])
      .mockResolvedValueOnce([source])
      .mockResolvedValue([{ ...source, disabled_at: '2026-08-11T00:00:00+09:00' }])
    vi.spyOn(holidayCalendarSourcesApi, 'syncHolidayCalendarSource').mockResolvedValue(synced)
    vi.spyOn(holidayCalendarSourcesApi, 'revertLastHolidayCalendarSync').mockResolvedValue(source)
    vi.spyOn(holidayCalendarSourcesApi, 'disableHolidayCalendarSource').mockResolvedValue({
      ...source,
      disabled_at: '2026-08-11T00:00:00+09:00',
    })

    renderPage()
    await screen.findByText('内閣府祝日カレンダー')

    await userEvent.click(screen.getByRole('button', { name: '今すぐ同期' }))
    await waitFor(() => expect(holidayCalendarSourcesApi.syncHolidayCalendarSource).toHaveBeenCalledWith('source-1'))
    expect(await screen.findByText('同期済み')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '直前の同期を取消す' }))
    await waitFor(() =>
      expect(holidayCalendarSourcesApi.revertLastHolidayCalendarSync).toHaveBeenCalledWith('source-1'),
    )
    expect(await screen.findByText('未同期')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '無効化する' }))
    await waitFor(() =>
      expect(holidayCalendarSourcesApi.disableHolidayCalendarSource).toHaveBeenCalledWith('source-1'),
    )
    expect(await screen.findByText('無効化済み')).toBeInTheDocument()
  })
})
