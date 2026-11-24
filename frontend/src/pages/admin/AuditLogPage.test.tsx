import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as auditLogApi from '../../api/auditLog'
import type { Paginated, StoredEvent } from '../../api/types'
import { AuditLogPage } from './AuditLogPage'

const event: StoredEvent = {
  id: 'evt-1',
  event_id: 'evt-1',
  aggregate_type: 'workflow_request',
  aggregate_id: '1',
  version: 1,
  event_type: 'workflow_request.drafted',
  payload: { title: 'タクシー代' },
  occurred_at: '2026-07-01T00:00:00+09:00',
}

const paginated: Paginated<StoredEvent> = {
  data: [event],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntry = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(auditLogApi, 'fetchAuditLog').mockResolvedValue(paginated)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <AuditLogPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** 日本語ロケールのreact-day-pickerの日付ボタンのaria-labelを組み立てる。 */
function dayButtonLabel(date: Date): string {
  return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日${date.toLocaleDateString('ja-JP', {
    weekday: 'long',
  })}`
}

async function navigateToMonth(user: ReturnType<typeof userEvent.setup>, targetYear: number, targetMonthIndex: number): Promise<void> {
  const label = screen.getByRole('grid').getAttribute('aria-label') ?? ''
  const match = label.match(/(\d{4})年(\d{1,2})月/)
  if (!match) throw new Error(`カレンダーの年月を読み取れませんでした: ${label}`)
  const diff = (targetYear - Number(match[1])) * 12 + (targetMonthIndex - (Number(match[2]) - 1))
  if (diff === 0) return
  const button = screen.getByRole('button', { name: diff < 0 ? '前の月へ' : '次の月へ' })
  for (let i = 0; i < Math.abs(diff); i++) {
    // eslint-disable-next-line no-await-in-loop
    await user.click(button)
  }
}

async function pickDateRange(user: ReturnType<typeof userEvent.setup>, fromIso: string, toIso: string): Promise<void> {
  await user.click(screen.getByRole('button', { name: '期間' }))
  const from = new Date(`${fromIso}T00:00:00`)
  const to = new Date(`${toIso}T00:00:00`)
  await navigateToMonth(user, from.getFullYear(), from.getMonth())
  await user.click(await screen.findByRole('button', { name: dayButtonLabel(from) }))
  await navigateToMonth(user, to.getFullYear(), to.getMonth())
  await user.click(await screen.findByRole('button', { name: dayButtonLabel(to) }))
  await user.click(await screen.findByRole('button', { name: '適用' }))
}

describe('AuditLogPage', () => {
  it('shows events and the total count', async () => {
    renderPage()

    expect(await screen.findByText('workflow_request.drafted')).toBeInTheDocument()
    expect(screen.queryByText(/^全\d+件/)).not.toBeInTheDocument()
  })

  it('requeries with the entered event type after the debounce and clears page', async () => {
    renderPage('/?page=2')
    await screen.findByText('workflow_request.drafted')

    await userEvent.type(screen.getByLabelText('イベント種別'), 'workflow_request.drafted')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?event_type=workflow_request\.drafted$/))
    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(
        expect.objectContaining({ event_type: 'workflow_request.drafted', page: 1 }),
      ),
    )
  })

  it('keeps the detail fields collapsed by default and sends a detail value after the debounce', async () => {
    renderPage()
    await screen.findByText('workflow_request.drafted')

    expect(screen.queryByLabelText('対象タイプ')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '詳細条件' }))
    await userEvent.type(screen.getByLabelText('対象タイプ'), 'workflow_request')

    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(
        expect.objectContaining({ aggregate_type: 'workflow_request' }),
      ),
    )
  })

  it('opens the detail fields initially with the count when a detail value is in the URL', async () => {
    renderPage('/?aggregate_type=workflow_request&aggregate_id=abc')

    expect(await screen.findByRole('button', { name: '詳細条件(2)' })).toBeInTheDocument()
    expect(screen.getByLabelText('対象タイプ')).toHaveValue('workflow_request')
    expect(screen.getByLabelText('対象ID')).toHaveValue('abc')
  })

  it('clears every condition including the detail ones with "検索条件をクリア"', async () => {
    renderPage('/?aggregate_type=workflow_request&event_type=x&from=2026-07-01&to=2026-07-05&page=2')

    await userEvent.click((await screen.findAllByRole('button', { name: '検索条件をクリア' }))[0])

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^$/))
    expect(screen.getByLabelText('イベント種別')).toHaveValue('')
  })

  it('puts from and to in the URL when a period is picked', async () => {
    renderPage('/?page=2')
    await screen.findByText('workflow_request.drafted')

    await pickDateRange(userEvent.setup(), '2026-07-01', '2026-07-10')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?from=2026-07-01&to=2026-07-10$/))
    expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2026-07-01', to: '2026-07-10', page: 1 }))
  })

  it('treats a URL with only "to" as no period and drops "to" from the URL', async () => {
    renderPage('/?to=2026-07-10&event_type=x')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent(/^\?event_type=x$/))
    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(expect.objectContaining({ from: undefined, to: undefined })),
    )
  })

  it('downloads the CSV when the button is clicked', async () => {
    vi.spyOn(auditLogApi, 'downloadAuditLogCsv').mockResolvedValue(undefined)
    renderPage()
    await screen.findByText('workflow_request.drafted')

    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() => expect(auditLogApi.downloadAuditLogCsv).toHaveBeenCalled())
  })

  it('drops invalid from/to/page from the URL and shows the default', async () => {
    renderPage('/?from=abc&to=2026-02-30&page=-1&aggregate_type=workflow_request')

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?aggregate_type=workflow_request'))
    expect(screen.getByTestId('location-search').textContent).not.toMatch(/from|to=|page/)
    await waitFor(() =>
      expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(
        expect.objectContaining({ aggregate_type: 'workflow_request', from: undefined, to: undefined, page: 1 }),
      ),
    )
  })

  it('keeps a valid from date in the URL', async () => {
    renderPage('/?from=2026-07-01')

    await screen.findByText('workflow_request.drafted')
    expect(screen.getByTestId('location-search')).toHaveTextContent('?from=2026-07-01')
    expect(auditLogApi.fetchAuditLog).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2026-07-01' }))
  })
})
